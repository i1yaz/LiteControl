<?php

declare(strict_types=1);

namespace App\Domains\SourceControl\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\SourceControl\Models\GitProvider;
use App\Domains\SourceControl\ValueObjects\GitHubAppName;
use App\Domains\SourceControl\ValueObjects\GitHubOrgName;
use App\Domains\SourceControl\ValueObjects\SafeReturnPath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InitiateGitHubAppManifestAction
{
    public const STATE_CACHE_PREFIX = 'github_app_manifest_state:';

    public const STATE_TTL_SECONDS = 1800;

    public function __construct(
        private readonly EvaluatePanelUrlGateAction $evaluatePanelUrlGate,
    ) {}

    /**
     * @return array{
     *     action_url: string,
     *     manifest: array<string, mixed>,
     *     state: string,
     *     provider_uuid: string,
     * }
     */
    public function handle(
        User $user,
        ?string $requestHost = null,
        ?string $label = null,
        ?GitHubOrgName $orgName = null,
        ?SafeReturnPath $returnTo = null,
        ?User $owner = null,
    ): array {
        $ownerUser = $owner ?? $user;

        $gate = $this->evaluatePanelUrlGate->handle(requestHost: $requestHost);
        if (! $gate->allowed || $gate->configuredUrl === null) {
            throw ValidationException::withMessages([
                'panel_url' => [$gate->message],
            ]);
        }

        $maxProviders = (int) config('litecontrol.max_git_providers_per_user', 10);
        $currentCount = GitProvider::query()->forUser($ownerUser->id)->count();
        if ($currentCount >= $maxProviders) {
            throw ValidationException::withMessages([
                'provider' => ["You have reached the maximum limit of {$maxProviders} connected Source Control providers."],
            ]);
        }

        $configuredPanelUrl = rtrim($gate->configuredUrl, '/');
        $host = (string) (parse_url($configuredPanelUrl, PHP_URL_HOST) ?: 'panel');

        $providerUuid = (string) Str::uuid();
        $appName = GitHubAppName::fromHost($host);
        $webhookUrl = "{$configuredPanelUrl}/api/webhooks/git-providers/{$providerUuid}";
        $redirectUrl = "{$configuredPanelUrl}/settings/source-control/github/callback";
        $setupUrl = "{$configuredPanelUrl}/settings/source-control/github/setup";

        $manifest = [
            'name' => $appName->value,
            'url' => $configuredPanelUrl,
            'hook_attributes' => [
                'url' => $webhookUrl,
                'active' => true,
            ],
            'redirect_url' => $redirectUrl,
            'setup_url' => $setupUrl,
            'public' => false,
            'setup_on_update' => true,
            'default_permissions' => [
                'contents' => 'read',
                'metadata' => 'read',
            ],
            'default_events' => [
                'push',
                'repository',
            ],
        ];

        $state = bin2hex(random_bytes(32));
        $normalizedLabel = $label !== null && trim($label) !== '' ? trim($label) : null;
        $safeReturnPath = ($returnTo ?? SafeReturnPath::fromNullable(null))->value;

        Cache::put(
            self::STATE_CACHE_PREFIX.$state,
            [
                'user_id' => $user->id,
                'owner_user_id' => $ownerUser->id,
                'provider_uuid' => $providerUuid,
                'label' => $normalizedLabel,
                'return_to' => $safeReturnPath,
                'configured_webhook_url' => $webhookUrl,
            ],
            self::STATE_TTL_SECONDS,
        );

        $actionUrl = $orgName !== null
            ? "https://github.com/organizations/{$orgName->value}/settings/apps/new?state={$state}"
            : "https://github.com/settings/apps/new?state={$state}";

        Log::info('Initiated GitHub App manifest registration flow.', [
            'user_id' => $user->id,
            'provider_uuid' => $providerUuid,
            'app_name' => $appName->value,
            'org_name' => $orgName?->value,
        ]);

        return [
            'action_url' => $actionUrl,
            'manifest' => $manifest,
            'state' => $state,
            'provider_uuid' => $providerUuid,
        ];
    }
}
