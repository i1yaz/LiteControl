<?php

declare(strict_types=1);

namespace App\Domains\SourceControl\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use App\Domains\SourceControl\Contracts\GitProviderDriverInterface;
use App\Domains\SourceControl\Enums\GitProviderStatus;
use App\Domains\SourceControl\Exceptions\GitProviderApiException;
use App\Domains\SourceControl\Models\GitProvider;
use App\Domains\SourceControl\ValueObjects\SafeReturnPath;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CompleteGitHubAppInstallationAction
{
    public function __construct(
        private readonly GitProviderDriverInterface $driver,
        private readonly AuditLoggerInterface $auditLogger,
    ) {}

    /**
     * @return array{provider: GitProvider, return_to: string}
     */
    public function handle(
        User $user,
        int $installationId,
        ?string $state = null,
        ?string $setupAction = null,
        ?string $ipAddress = null,
    ): array {
        $normalizedState = is_string($state) && trim($state) !== '' ? trim($state) : null;
        $stateKey = $normalizedState !== null
            ? CompleteGitHubAppManifestAction::INSTALL_STATE_CACHE_PREFIX.$normalizedState
            : null;

        $returnTo = SafeReturnPath::DEFAULT_PATH;

        if ($stateKey !== null) {
            [$provider, $installation, $returnTo] = $this->resolveWithState($user, $installationId, $stateKey);
        } else {
            [$provider, $installation] = $this->resolveWithoutState($user, $installationId);
        }

        $account = is_array($installation['account'] ?? null) ? $installation['account'] : [];
        $accountLogin = is_string($account['login'] ?? null) ? $account['login'] : $provider->external_account_login;
        $accountType = is_string($account['type'] ?? null) ? $account['type'] : $provider->external_account_type;
        $repositorySelection = is_string($installation['repository_selection'] ?? null)
            ? $installation['repository_selection']
            : $provider->repository_selection;

        $updatedProvider = DB::transaction(function () use (
            $user,
            $provider,
            $installationId,
            $accountLogin,
            $accountType,
            $repositorySelection,
            $setupAction,
            $ipAddress,
        ): GitProvider {
            $provider->forceFill([
                'installation_id' => $installationId,
                'external_account_login' => $accountLogin,
                'external_account_type' => $accountType,
                'repository_selection' => $repositorySelection,
                'status' => GitProviderStatus::Active,
                'suspended_at' => null,
                'last_verified_at' => CarbonImmutable::now(),
            ])->save();

            $this->auditLogger->log([
                'user_id' => $user->id,
                'action' => 'source_control.github_app_installed',
                'command' => "github-app setup {$installationId}",
                'status' => 'success',
                'ip_address' => $ipAddress,
                'details' => [
                    'git_provider_id' => $provider->id,
                    'git_provider_uuid' => $provider->uuid,
                    'installation_id' => $installationId,
                    'external_account_login' => $accountLogin,
                    'external_account_type' => $accountType,
                    'repository_selection' => $repositorySelection,
                    'setup_action' => $setupAction,
                ],
            ]);

            return $provider->refresh();
        });

        if ($stateKey !== null) {
            Cache::forget($stateKey);
        }

        $this->driver->bustRepositoriesCache($updatedProvider);

        Log::info('Activated GitHub App provider installation.', [
            'user_id' => $user->id,
            'git_provider_id' => $updatedProvider->id,
            'installation_id' => $installationId,
            'setup_action' => $setupAction,
        ]);

        return [
            'provider' => $updatedProvider,
            'return_to' => SafeReturnPath::fromNullable($returnTo)->value,
        ];
    }

    /**
     * @return array{0: GitProvider, 1: array<string, mixed>, 2: string}
     */
    private function resolveWithState(User $user, int $installationId, string $stateKey): array
    {
        $statePayload = Cache::get($stateKey);
        if (! is_array($statePayload) || (int) ($statePayload['user_id'] ?? 0) !== $user->id) {
            throw new AuthorizationException('GitHub App installation state is invalid, expired, or does not belong to your account.');
        }

        $providerId = (int) ($statePayload['git_provider_id'] ?? 0);
        $ownerUserId = (int) ($statePayload['owner_user_id'] ?? $user->id);
        $provider = GitProvider::query()
            ->when(! $user->isAdmin(), static fn ($query) => $query->forUser($ownerUserId))
            ->where('provider', 'github_app')
            ->find($providerId);

        if (! $provider) {
            throw new AuthorizationException('The referenced GitHub App provider was not found for your account.');
        }

        try {
            $installation = $this->driver->getInstallation($provider, $installationId, markReauthOnFailure: false);
        } catch (GitProviderApiException $e) {
            Log::warning('Rejected GitHub App installation callback after JWT verification failed.', [
                'user_id' => $user->id,
                'git_provider_id' => $provider->id,
                'installation_id' => $installationId,
                'status_code' => $e->statusCode,
            ]);

            throw new AuthorizationException('Could not verify ownership of the provided GitHub App installation ID.');
        }

        $returnTo = is_string($statePayload['return_to'] ?? null)
            ? $statePayload['return_to']
            : SafeReturnPath::DEFAULT_PATH;

        return [$provider, $installation, $returnTo];
    }

    /**
     * @return array{0: GitProvider, 1: array<string, mixed>}
     */
    private function resolveWithoutState(User $user, int $installationId): array
    {
        $candidates = GitProvider::query()
            ->forUser($user->id)
            ->where('provider', 'github_app')
            ->whereIn('status', [
                GitProviderStatus::PendingInstallation->value,
                GitProviderStatus::Active->value,
            ])
            ->orderByRaw(
                'CASE WHEN status = ? THEN 0 WHEN status = ? THEN 1 ELSE 2 END',
                [
                    GitProviderStatus::PendingInstallation->value,
                    GitProviderStatus::Active->value,
                ]
            )
            ->latest('id')
            ->get();

        foreach ($candidates as $candidate) {
            try {
                $installation = $this->driver->getInstallation($candidate, $installationId, markReauthOnFailure: false);

                return [$candidate, $installation];
            } catch (GitProviderApiException) {
                continue;
            }
        }

        Log::warning('Rejected stateless GitHub App setup callback; no owned provider verified the installation ID.', [
            'user_id' => $user->id,
            'installation_id' => $installationId,
            'candidates_checked' => $candidates->count(),
        ]);

        throw new AuthorizationException('Could not verify ownership of the provided GitHub App installation ID.');
    }
}
