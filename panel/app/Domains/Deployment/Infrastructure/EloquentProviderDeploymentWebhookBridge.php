<?php

declare(strict_types=1);

namespace App\Domains\Deployment\Infrastructure;

use App\Domains\Deployment\Actions\DispatchDomainDeploymentAction;
use App\Domains\Deployment\Models\DomainDeployment;
use App\Domains\Deployment\Models\DomainDeploymentConfig;
use App\Domains\SourceControl\Contracts\ProviderDeploymentWebhookBridgeInterface;
use App\Domains\SourceControl\Enums\SourceMode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EloquentProviderDeploymentWebhookBridge implements ProviderDeploymentWebhookBridgeInterface
{
    public function __construct(
        private readonly DispatchDomainDeploymentAction $dispatcher,
    ) {}

    public function hasActiveDeploymentForCommit(
        int $gitProviderId,
        int $providerUserId,
        string $remoteRepoId,
        string $branch,
        string $commitSha,
    ): bool {
        $configs = DomainDeploymentConfig::query()
            ->with('domain:id,user_id')
            ->where('source_mode', SourceMode::Provider->value)
            ->where('git_provider_id', $gitProviderId)
            ->where('remote_repo_id', $remoteRepoId)
            ->where('branch', $branch)
            ->where('auto_deploy', true)
            ->get();

        $ownedDomainIds = [];
        foreach ($configs as $config) {
            if ($config->domain !== null && (int) $config->domain->user_id === $providerUserId) {
                $ownedDomainIds[] = $config->domain_id;
            }
        }

        if ($ownedDomainIds === []) {
            return false;
        }

        $activeExists = DomainDeployment::query()
            ->whereIn('domain_id', $ownedDomainIds)
            ->where('commit_sha', $commitSha)
            ->whereIn('status', ['queued', 'running'])
            ->exists();

        if ($activeExists) {
            return true;
        }

        foreach ($ownedDomainIds as $domainId) {
            $pending = Cache::get(DispatchDomainDeploymentAction::pendingKey($domainId));
            if (is_array($pending) && ($pending['commit_sha'] ?? null) === $commitSha) {
                return true;
            }
        }

        return false;
    }

    public function dispatchMatchingPushDeployments(
        int $gitProviderId,
        int $providerUserId,
        string $remoteRepoId,
        string $branch,
        ?string $commitSha,
        ?string $commitMessage = null,
        ?string $commitAuthor = null,
    ): array {
        $configs = DomainDeploymentConfig::query()
            ->with('domain:id,user_id,domain')
            ->where('source_mode', SourceMode::Provider->value)
            ->where('git_provider_id', $gitProviderId)
            ->where('remote_repo_id', $remoteRepoId)
            ->where('branch', $branch)
            ->where('auto_deploy', true)
            ->get();

        $matched = 0;
        $dispatched = 0;
        $coalesced = 0;
        $skippedCrossTenant = 0;

        foreach ($configs as $config) {
            $domain = $config->domain;
            if ($domain === null || (int) $domain->user_id !== $providerUserId) {
                $skippedCrossTenant++;

                Log::alert('Blocked cross-tenant webhook deployment attempt: domain owner does not match git provider owner.', [
                    'git_provider_id' => $gitProviderId,
                    'provider_user_id' => $providerUserId,
                    'domain_id' => $config->domain_id,
                    'domain_user_id' => $domain?->user_id,
                    'remote_repo_id' => $remoteRepoId,
                    'branch' => $branch,
                ]);

                continue;
            }

            $matched++;

            $result = $this->dispatcher->handle(
                config: $config,
                commitSha: $commitSha,
                trigger: 'webhook',
                commitMessage: $commitMessage,
                commitAuthor: $commitAuthor,
            );

            if ($result['status'] === 'dispatched') {
                $dispatched++;
            } else {
                $coalesced++;
            }
        }

        return [
            'matched' => $matched,
            'dispatched' => $dispatched,
            'coalesced' => $coalesced,
            'skipped_cross_tenant' => $skippedCrossTenant,
        ];
    }

    public function syncRepositoryFullName(int $gitProviderId, string $remoteRepoId, string $fullName): int
    {
        return DomainDeploymentConfig::query()
            ->where('git_provider_id', $gitProviderId)
            ->where('remote_repo_id', $remoteRepoId)
            ->update([
                'remote_repo_full_name' => $fullName,
            ]);
    }
}
