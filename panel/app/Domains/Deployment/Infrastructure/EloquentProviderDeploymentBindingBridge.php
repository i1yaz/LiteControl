<?php

declare(strict_types=1);

namespace App\Domains\Deployment\Infrastructure;

use App\Domains\Deployment\Models\DomainDeploymentConfig;
use App\Domains\SourceControl\Contracts\ProviderDeploymentBindingBridgeInterface;
use App\Domains\SourceControl\Enums\SourceMode;
use Illuminate\Support\Str;

class EloquentProviderDeploymentBindingBridge implements ProviderDeploymentBindingBridgeInterface
{
    public function countLinkedDomains(int $gitProviderId): int
    {
        return DomainDeploymentConfig::query()
            ->where('git_provider_id', $gitProviderId)
            ->count();
    }

    public function listLinkedDomains(int $gitProviderId): array
    {
        return DomainDeploymentConfig::query()
            ->with('domain:id,domain')
            ->where('git_provider_id', $gitProviderId)
            ->get()
            ->map(fn (DomainDeploymentConfig $config): array => [
                'domain_id' => $config->domain_id,
                'domain' => (string) ($config->domain?->domain ?? "Domain #{$config->domain_id}"),
            ])
            ->values()
            ->all();
    }

    public function listLinkedDomainsByProviderIds(array $gitProviderIds): array
    {
        if ($gitProviderIds === []) {
            return [];
        }

        $grouped = [];
        foreach ($gitProviderIds as $id) {
            $grouped[$id] = [];
        }

        $configs = DomainDeploymentConfig::query()
            ->with('domain:id,domain')
            ->whereIn('git_provider_id', $gitProviderIds)
            ->get();

        foreach ($configs as $config) {
            $providerId = (int) $config->git_provider_id;
            $grouped[$providerId][] = [
                'domain_id' => $config->domain_id,
                'domain' => (string) ($config->domain?->domain ?? "Domain #{$config->domain_id}"),
            ];
        }

        return $grouped;
    }

    public function detachLinkedDomainsToManual(int $gitProviderId): int
    {
        return DomainDeploymentConfig::query()
            ->where('git_provider_id', $gitProviderId)
            ->update([
                'source_mode' => SourceMode::Manual->value,
                'git_provider_id' => null,
                'remote_repo_id' => null,
                'remote_repo_full_name' => null,
                'auto_deploy' => false,
            ]);
    }

    public function getDomainSourceState(int $domainId): array
    {
        $config = DomainDeploymentConfig::query()
            ->where('domain_id', $domainId)
            ->first();

        if ($config === null) {
            return [
                'exists' => false,
                'source_mode' => SourceMode::Manual->value,
                'git_provider_id' => null,
                'has_manual_credentials' => false,
                'has_api_token' => false,
                'has_deploy_key' => false,
            ];
        }

        $hasApiToken = ! empty($config->getRawOriginal('api_token_encrypted'));
        $hasDeployKey = ! empty($config->deploy_key_public) || ! empty($config->getRawOriginal('deploy_key_private_encrypted'));
        $hasWebhookSecret = ! empty($config->getRawOriginal('webhook_secret_encrypted'));

        return [
            'exists' => true,
            'source_mode' => $config->source_mode instanceof SourceMode
                ? $config->source_mode->value
                : (string) ($config->source_mode ?: SourceMode::Manual->value),
            'git_provider_id' => $config->git_provider_id,
            'has_manual_credentials' => $hasApiToken || $hasDeployKey || $hasWebhookSecret,
            'has_api_token' => $hasApiToken,
            'has_deploy_key' => $hasDeployKey,
        ];
    }

    public function bindDomainToProvider(
        int $domainId,
        int $gitProviderId,
        string $remoteRepoId,
        string $remoteRepoFullName,
        string $branch,
        bool $autoDeploy,
        bool $clearManualCredentials,
        array $deploymentSettings = [],
    ): array {
        $config = DomainDeploymentConfig::firstOrCreate(
            ['domain_id' => $domainId],
            ['webhook_token' => Str::random(40)],
        );

        $wasSimpleMode = $config->mode === 'simple';
        $hadManualCredentials = ! empty($config->getRawOriginal('api_token_encrypted'))
            || ! empty($config->deploy_key_public)
            || ! empty($config->getRawOriginal('deploy_key_private_encrypted'))
            || ! empty($config->getRawOriginal('webhook_secret_encrypted'));

        $payload = [
            'mode' => $deploymentSettings['mode'] ?? 'atomic',
            'source_mode' => SourceMode::Provider->value,
            'git_provider_id' => $gitProviderId,
            'remote_repo_id' => $remoteRepoId,
            'remote_repo_full_name' => $remoteRepoFullName,
            'repo_provider' => 'github',
            'repo_url' => "https://github.com/{$remoteRepoFullName}.git",
            'branch' => $branch,
            'auto_deploy' => $autoDeploy,
            'fetch_driver' => $deploymentSettings['fetch_driver'] ?? 'provider_tarball',
        ];

        foreach (['preset', 'web_subpath', 'php_version', 'keep_releases', 'shared_dirs', 'shared_files', 'health_check_enabled', 'health_check_path', 'auto_rollback'] as $key) {
            if (array_key_exists($key, $deploymentSettings)) {
                $payload[$key] = $deploymentSettings[$key];
            }
        }

        $clearedManualCredentials = false;
        if ($clearManualCredentials && $hadManualCredentials) {
            $payload['api_token_encrypted'] = null;
            $payload['deploy_key_public'] = null;
            $payload['deploy_key_private_encrypted'] = null;
            $payload['webhook_secret_encrypted'] = null;
            $clearedManualCredentials = true;
        }

        $config->update($payload);

        return [
            'cleared_manual_credentials' => $clearedManualCredentials,
            'was_simple_mode' => $wasSimpleMode,
        ];
    }

    public function switchDomainToManual(int $domainId): void
    {
        $config = DomainDeploymentConfig::firstOrCreate(
            ['domain_id' => $domainId],
            ['webhook_token' => Str::random(40)],
        );

        $config->update([
            'source_mode' => SourceMode::Manual->value,
            'git_provider_id' => null,
            'remote_repo_id' => null,
            'remote_repo_full_name' => null,
            'auto_deploy' => false,
        ]);
    }

    public function switchDomainToProviderMode(
        int $domainId,
        bool $clearManualCredentials,
        ?int $gitProviderId = null,
        ?string $remoteRepoId = null,
        ?string $remoteRepoFullName = null,
        ?string $branch = null,
        bool $autoDeploy = true,
    ): array {
        $config = DomainDeploymentConfig::firstOrCreate(
            ['domain_id' => $domainId],
            ['webhook_token' => Str::random(40)],
        );

        $hadManualCredentials = ! empty($config->getRawOriginal('api_token_encrypted'))
            || ! empty($config->deploy_key_public)
            || ! empty($config->getRawOriginal('deploy_key_private_encrypted'))
            || ! empty($config->getRawOriginal('webhook_secret_encrypted'));

        $payload = [
            'mode' => 'atomic',
            'source_mode' => SourceMode::Provider->value,
            'auto_deploy' => $autoDeploy,
            'fetch_driver' => 'provider_tarball',
        ];

        if ($gitProviderId !== null) {
            $payload['git_provider_id'] = $gitProviderId;
        }
        if ($remoteRepoId !== null) {
            $payload['remote_repo_id'] = $remoteRepoId;
        }
        if ($remoteRepoFullName !== null) {
            $payload['remote_repo_full_name'] = $remoteRepoFullName;
            $payload['repo_provider'] = 'github';
            $payload['repo_url'] = "https://github.com/{$remoteRepoFullName}.git";
        }
        if ($branch !== null && $branch !== '') {
            $payload['branch'] = $branch;
        }

        $clearedManualCredentials = false;
        if ($clearManualCredentials && $hadManualCredentials) {
            $payload['api_token_encrypted'] = null;
            $payload['deploy_key_public'] = null;
            $payload['deploy_key_private_encrypted'] = null;
            $payload['webhook_secret_encrypted'] = null;
            $clearedManualCredentials = true;
        }

        $config->update($payload);

        return [
            'cleared_manual_credentials' => $clearedManualCredentials,
        ];
    }
}
