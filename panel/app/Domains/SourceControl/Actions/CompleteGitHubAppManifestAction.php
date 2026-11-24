<?php

declare(strict_types=1);

namespace App\Domains\SourceControl\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use App\Domains\SourceControl\Contracts\GitProviderDriverInterface;
use App\Domains\SourceControl\Enums\GitProviderStatus;
use App\Domains\SourceControl\Exceptions\OrphanedGitHubAppException;
use App\Domains\SourceControl\Models\GitProvider;
use App\Domains\SourceControl\ValueObjects\ProviderCredentials;
use App\Domains\SourceControl\ValueObjects\SafeReturnPath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class CompleteGitHubAppManifestAction
{
    public const INSTALL_STATE_CACHE_PREFIX = 'github_app_install_state:';

    public const INSTALL_STATE_TTL_SECONDS = 1800;

    public function __construct(
        private readonly GitProviderDriverInterface $driver,
        private readonly AuditLoggerInterface $auditLogger,
    ) {}

    /**
     * @return array{provider: GitProvider, redirect_url: string, install_state: string}
     */
    public function handle(
        User $user,
        string $code,
        string $state,
        ?string $ipAddress = null,
    ): array {
        $stateKey = InitiateGitHubAppManifestAction::STATE_CACHE_PREFIX.$state;
        $statePayload = Cache::get($stateKey);

        if (! is_array($statePayload) || (int) ($statePayload['user_id'] ?? 0) !== $user->id) {
            throw ValidationException::withMessages([
                'state' => ['The GitHub App registration state is invalid, expired, or does not belong to your account. Please start the connection flow again.'],
            ]);
        }

        $conversion = $this->driver->convertManifest($code);

        $externalAppId = isset($conversion['id']) ? (string) $conversion['id'] : '';
        $slug = is_string($conversion['slug'] ?? null) ? trim($conversion['slug']) : '';
        $pem = is_string($conversion['pem'] ?? null) ? $conversion['pem'] : '';
        $clientId = is_string($conversion['client_id'] ?? null) ? $conversion['client_id'] : '';
        $clientSecret = is_string($conversion['client_secret'] ?? null) ? $conversion['client_secret'] : '';
        $webhookSecret = is_string($conversion['webhook_secret'] ?? null) ? $conversion['webhook_secret'] : '';
        $appName = is_string($conversion['name'] ?? null) && trim($conversion['name']) !== ''
            ? trim($conversion['name'])
            : ($slug !== '' ? $slug : 'GitHub App');

        if ($externalAppId === '' || $slug === '' || $webhookSecret === '') {
            throw ValidationException::withMessages([
                'code' => ['GitHub returned an incomplete App manifest conversion payload.'],
            ]);
        }

        $credentials = new ProviderCredentials(
            pem: $pem,
            clientId: $clientId,
            clientSecret: $clientSecret,
        );

        $owner = is_array($conversion['owner'] ?? null) ? $conversion['owner'] : [];
        $ownerLogin = is_string($owner['login'] ?? null) ? $owner['login'] : null;
        $ownerType = is_string($owner['type'] ?? null) ? $owner['type'] : null;

        $providerUuid = is_string($statePayload['provider_uuid'] ?? null) && $statePayload['provider_uuid'] !== ''
            ? $statePayload['provider_uuid']
            : null;
        $label = is_string($statePayload['label'] ?? null) && trim($statePayload['label']) !== ''
            ? trim($statePayload['label'])
            : $appName;
        $configuredWebhookUrl = is_string($statePayload['configured_webhook_url'] ?? null)
            ? $statePayload['configured_webhook_url']
            : null;
        $returnTo = SafeReturnPath::fromNullable(
            is_string($statePayload['return_to'] ?? null) ? $statePayload['return_to'] : null
        )->value;

        try {
            $provider = DB::transaction(function () use (
                $user,
                $providerUuid,
                $label,
                $externalAppId,
                $slug,
                $ownerLogin,
                $ownerType,
                $credentials,
                $webhookSecret,
                $configuredWebhookUrl,
                $ipAddress,
            ): GitProvider {
                $provider = GitProvider::create([
                    'uuid' => $providerUuid,
                    'user_id' => $user->id,
                    'provider' => 'github_app',
                    'label' => $label,
                    'base_url' => 'https://api.github.com',
                    'external_app_id' => $externalAppId,
                    'external_app_slug' => $slug,
                    'external_account_login' => $ownerLogin,
                    'external_account_type' => $ownerType,
                    'credentials' => $credentials->toArray(),
                    'webhook_secret' => $webhookSecret,
                    'configured_webhook_url' => $configuredWebhookUrl,
                    'status' => GitProviderStatus::PendingInstallation,
                ]);

                $this->auditLogger->log([
                    'user_id' => $user->id,
                    'action' => 'source_control.github_app_created',
                    'command' => "github-app manifest-convert {$slug}",
                    'status' => 'success',
                    'ip_address' => $ipAddress,
                    'details' => [
                        'git_provider_id' => $provider->id,
                        'git_provider_uuid' => $provider->uuid,
                        'external_app_id' => $externalAppId,
                        'external_app_slug' => $slug,
                        'external_account_login' => $ownerLogin,
                    ],
                ]);

                return $provider;
            });
        } catch (Throwable $e) {
            $deletionUrl = "https://github.com/settings/apps/{$slug}/advanced";

            Log::error('Failed to persist GitProvider after GitHub App manifest conversion succeeded; orphaned App may exist on GitHub.', [
                'user_id' => $user->id,
                'external_app_id' => $externalAppId,
                'external_app_slug' => $slug,
                'deletion_url' => $deletionUrl,
                'exception' => $e->getMessage(),
            ]);

            throw new OrphanedGitHubAppException(
                message: "GitHub created the app [{$slug}], but LiteControl could not save it. Delete the orphaned app at {$deletionUrl} and try again.",
                appSlug: $slug,
                deletionUrl: $deletionUrl,
                previous: $e,
            );
        }

        // Persist succeeded; only NOW consume the one-time manifest state.
        Cache::forget($stateKey);

        $installState = bin2hex(random_bytes(32));
        Cache::put(
            self::INSTALL_STATE_CACHE_PREFIX.$installState,
            [
                'user_id' => $user->id,
                'git_provider_id' => $provider->id,
                'return_to' => $returnTo,
            ],
            self::INSTALL_STATE_TTL_SECONDS,
        );

        Log::info('Converted GitHub App manifest and persisted pending provider.', [
            'user_id' => $user->id,
            'git_provider_id' => $provider->id,
            'external_app_slug' => $slug,
        ]);

        return [
            'provider' => $provider,
            'redirect_url' => "https://github.com/apps/{$slug}/installations/new?state={$installState}",
            'install_state' => $installState,
        ];
    }
}
