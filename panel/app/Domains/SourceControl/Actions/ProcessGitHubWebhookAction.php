<?php

declare(strict_types=1);

namespace App\Domains\SourceControl\Actions;

use App\Domains\Shared\Contracts\AuditLoggerInterface;
use App\Domains\SourceControl\Contracts\GitProviderDriverInterface;
use App\Domains\SourceControl\Contracts\ProviderDeploymentWebhookBridgeInterface;
use App\Domains\SourceControl\Enums\GitProviderStatus;
use App\Domains\SourceControl\Exceptions\GitProviderApiException;
use App\Domains\SourceControl\Models\GitProvider;
use App\Domains\SourceControl\ValueObjects\IncomingWebhookPayload;
use App\Domains\SourceControl\ValueObjects\WebhookProcessingResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ProcessGitHubWebhookAction
{
    public const DELIVERY_CACHE_PREFIX = 'github_webhook_delivery:';

    public const DELIVERY_CACHE_TTL_SECONDS = 86400;

    private const ZERO_SHA = '0000000000000000000000000000000000000000';

    public function __construct(
        private readonly GitProviderDriverInterface $driver,
        private readonly ProviderDeploymentWebhookBridgeInterface $deploymentBridge,
        private readonly AuditLoggerInterface $auditLogger,
    ) {}

    public static function deliveryCacheKey(int $providerId, string $deliveryId): string
    {
        return self::DELIVERY_CACHE_PREFIX.$providerId.':'.$deliveryId;
    }

    public function handle(
        string $providerUuid,
        IncomingWebhookPayload $incoming,
        ?string $ipAddress = null,
    ): WebhookProcessingResult {
        $provider = GitProvider::query()
            ->where('uuid', $providerUuid)
            ->first();

        if (! $provider) {
            return new WebhookProcessingResult(
                httpStatus: 404,
                status: 'error',
                reason: 'provider_not_found',
            );
        }

        $secret = $provider->safeWebhookSecret();
        if ($secret === null || ! $incoming->verifySignature($secret)) {
            Log::warning('Rejected GitHub webhook with invalid or missing signature.', [
                'git_provider_id' => $provider->id,
                'git_provider_uuid' => $provider->uuid,
                'event' => $incoming->event,
                'delivery_id' => $incoming->deliveryId,
                'ip_address' => $ipAddress,
            ]);

            return new WebhookProcessingResult(
                httpStatus: 403,
                status: 'error',
                reason: 'invalid_signature',
            );
        }

        $now = CarbonImmutable::now();

        if ($incoming->event === 'ping') {
            $provider->forceFill([
                'webhook_verified_at' => $now,
                'last_webhook_at' => $now,
            ])->save();

            Log::info('Verified GitHub App webhook ping.', [
                'git_provider_id' => $provider->id,
                'delivery_id' => $incoming->deliveryId,
            ]);

            return new WebhookProcessingResult(
                httpStatus: 200,
                status: 'ok',
                meta: ['event' => 'ping'],
            );
        }

        $provider->forceFill([
            'last_webhook_at' => $now,
        ])->save();

        return match ($incoming->event) {
            'push' => $this->handlePush($provider, $incoming),
            'installation' => $this->handleInstallation($provider, $incoming, $ipAddress),
            'installation_repositories' => $this->handleInstallationRepositories($provider, $incoming),
            'repository' => $this->handleRepository($provider, $incoming),
            default => new WebhookProcessingResult(
                httpStatus: 200,
                status: 'ignored',
                reason: 'unhandled_event',
                meta: ['event' => $incoming->event],
            ),
        };
    }

    private function handlePush(GitProvider $provider, IncomingWebhookPayload $incoming): WebhookProcessingResult
    {
        $payload = $incoming->payload;

        if (($payload['deleted'] ?? false) === true) {
            return new WebhookProcessingResult(
                httpStatus: 200,
                status: 'ignored',
                reason: 'branch_deleted',
            );
        }

        $headCommit = $payload['head_commit'] ?? null;
        $afterSha = is_string($payload['after'] ?? null) ? trim($payload['after']) : '';

        if (! is_array($headCommit) || $afterSha === '' || $afterSha === self::ZERO_SHA) {
            return new WebhookProcessingResult(
                httpStatus: 200,
                status: 'ignored',
                reason: 'no_head_commit',
            );
        }

        $ref = is_string($payload['ref'] ?? null) ? $payload['ref'] : '';
        if (! str_starts_with($ref, 'refs/heads/')) {
            return new WebhookProcessingResult(
                httpStatus: 200,
                status: 'ignored',
                reason: 'non_branch_ref',
            );
        }

        $branch = Str::after($ref, 'refs/heads/');
        if ($branch === '') {
            return new WebhookProcessingResult(
                httpStatus: 200,
                status: 'ignored',
                reason: 'empty_branch',
            );
        }

        $remoteRepoId = isset($payload['repository']['id']) ? (string) $payload['repository']['id'] : '';
        if ($remoteRepoId === '') {
            return new WebhookProcessingResult(
                httpStatus: 200,
                status: 'ignored',
                reason: 'missing_repository_id',
            );
        }

        $deliveryKey = $incoming->deliveryId !== null
            ? self::deliveryCacheKey($provider->id, $incoming->deliveryId)
            : null;

        if ($deliveryKey !== null && Cache::has($deliveryKey)) {
            $alreadyActive = $this->deploymentBridge->hasActiveDeploymentForCommit(
                gitProviderId: $provider->id,
                providerUserId: (int) $provider->user_id,
                remoteRepoId: $remoteRepoId,
                branch: $branch,
                commitSha: $afterSha,
            );

            if ($alreadyActive) {
                return new WebhookProcessingResult(
                    httpStatus: 200,
                    status: 'duplicate',
                    reason: 'delivery_already_processed',
                    meta: ['delivery_id' => $incoming->deliveryId],
                );
            }
        }

        $commitMessage = is_string($headCommit['message'] ?? null) ? $headCommit['message'] : null;
        $commitAuthor = is_string($headCommit['author']['name'] ?? null) ? $headCommit['author']['name'] : null;

        try {
            $summary = $this->deploymentBridge->dispatchMatchingPushDeployments(
                gitProviderId: $provider->id,
                providerUserId: (int) $provider->user_id,
                remoteRepoId: $remoteRepoId,
                branch: $branch,
                commitSha: $afterSha,
                commitMessage: $commitMessage,
                commitAuthor: $commitAuthor,
            );
        } catch (Throwable $e) {
            if ($deliveryKey !== null) {
                Cache::forget($deliveryKey);
            }

            throw $e;
        }

        if ($deliveryKey !== null) {
            Cache::add($deliveryKey, $afterSha, self::DELIVERY_CACHE_TTL_SECONDS);
        }

        return new WebhookProcessingResult(
            httpStatus: 200,
            status: 'processed',
            meta: [
                'event' => 'push',
                'branch' => $branch,
                'commit_sha' => $afterSha,
                ...$summary,
            ],
        );
    }

    private function handleInstallation(
        GitProvider $provider,
        IncomingWebhookPayload $incoming,
        ?string $ipAddress,
    ): WebhookProcessingResult {
        $action = is_string($incoming->payload['action'] ?? null) ? strtolower($incoming->payload['action']) : '';

        if (! in_array($action, ['deleted', 'suspend', 'unsuspend'], true)) {
            return new WebhookProcessingResult(
                httpStatus: 200,
                status: 'ignored',
                reason: 'unhandled_installation_action',
                meta: ['action' => $action],
            );
        }

        $installationId = (int) ($incoming->payload['installation']['id'] ?? $provider->installation_id ?? 0);
        if ($installationId <= 0) {
            return new WebhookProcessingResult(
                httpStatus: 200,
                status: 'ignored',
                reason: 'missing_installation_id',
            );
        }

        $previousStatus = $provider->status->value;
        $newStatus = $provider->status;
        $suspendedAt = $provider->suspended_at;

        try {
            $installation = $this->driver->getInstallation($provider, $installationId, markReauthOnFailure: false);

            if (! empty($installation['suspended_at'])) {
                $newStatus = GitProviderStatus::Suspended;
                $suspendedAt = CarbonImmutable::parse((string) $installation['suspended_at']);
            } else {
                $newStatus = GitProviderStatus::Active;
                $suspendedAt = null;
            }

            $provider->forceFill([
                'status' => $newStatus,
                'suspended_at' => $suspendedAt,
                'last_verified_at' => CarbonImmutable::now(),
            ])->save();
        } catch (GitProviderApiException $e) {
            if ($e->statusCode === 404) {
                $newStatus = GitProviderStatus::SourceUnavailable;
                $provider->forceFill([
                    'status' => $newStatus,
                    'last_verified_at' => CarbonImmutable::now(),
                ])->save();
            } else {
                throw $e;
            }
        }

        $this->driver->bustRepositoriesCache($provider);

        Log::info('Synchronized GitHub App installation state from webhook.', [
            'git_provider_id' => $provider->id,
            'installation_id' => $installationId,
            'webhook_action' => $action,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus->value,
            'delivery_id' => $incoming->deliveryId,
        ]);

        $this->auditLogger->log([
            'user_id' => $provider->user_id,
            'action' => 'source_control.installation_state_synced',
            'command' => "github-webhook installation {$action} {$installationId}",
            'status' => $newStatus === GitProviderStatus::Active ? 'success' : 'warning',
            'ip_address' => $ipAddress,
            'details' => [
                'git_provider_id' => $provider->id,
                'git_provider_uuid' => $provider->uuid,
                'installation_id' => $installationId,
                'webhook_action' => $action,
                'previous_status' => $previousStatus,
                'new_status' => $newStatus->value,
                'delivery_id' => $incoming->deliveryId,
            ],
        ]);

        return new WebhookProcessingResult(
            httpStatus: 200,
            status: 'processed',
            meta: [
                'event' => 'installation',
                'action' => $action,
                'provider_status' => $newStatus->value,
            ],
        );
    }

    private function handleInstallationRepositories(
        GitProvider $provider,
        IncomingWebhookPayload $incoming,
    ): WebhookProcessingResult {
        $this->driver->bustRepositoriesCache($provider);

        Log::info('Busted encrypted repository cache after installation_repositories webhook.', [
            'git_provider_id' => $provider->id,
            'delivery_id' => $incoming->deliveryId,
        ]);

        return new WebhookProcessingResult(
            httpStatus: 200,
            status: 'processed',
            meta: ['event' => 'installation_repositories'],
        );
    }

    private function handleRepository(
        GitProvider $provider,
        IncomingWebhookPayload $incoming,
    ): WebhookProcessingResult {
        $action = is_string($incoming->payload['action'] ?? null) ? strtolower($incoming->payload['action']) : '';

        if (! in_array($action, ['renamed', 'archived'], true)) {
            return new WebhookProcessingResult(
                httpStatus: 200,
                status: 'ignored',
                reason: 'unhandled_repository_action',
                meta: ['action' => $action],
            );
        }

        $remoteRepoId = isset($incoming->payload['repository']['id'])
            ? (string) $incoming->payload['repository']['id']
            : '';
        $fullName = is_string($incoming->payload['repository']['full_name'] ?? null)
            ? trim($incoming->payload['repository']['full_name'])
            : '';

        $updatedCount = 0;
        if ($remoteRepoId !== '' && $fullName !== '') {
            $updatedCount = $this->deploymentBridge->syncRepositoryFullName(
                gitProviderId: $provider->id,
                remoteRepoId: $remoteRepoId,
                fullName: $fullName,
            );
        }

        $this->driver->bustRepositoriesCache($provider);

        Log::info('Processed GitHub repository metadata webhook.', [
            'git_provider_id' => $provider->id,
            'action' => $action,
            'remote_repo_id' => $remoteRepoId,
            'remote_repo_full_name' => $fullName,
            'updated_configs' => $updatedCount,
            'delivery_id' => $incoming->deliveryId,
        ]);

        return new WebhookProcessingResult(
            httpStatus: 200,
            status: 'processed',
            meta: [
                'event' => 'repository',
                'action' => $action,
                'updated_configs' => $updatedCount,
            ],
        );
    }
}
