<?php

declare(strict_types=1);

namespace App\Domains\SourceControl\Contracts;

interface ProviderDeploymentWebhookBridgeInterface
{
    /**
     * Determine whether any matching provider-bound domain already has a queued or running
     * deployment for the given commit SHA.
     */
    public function hasActiveDeploymentForCommit(
        int $gitProviderId,
        int $providerUserId,
        string $remoteRepoId,
        string $branch,
        string $commitSha,
    ): bool;

    /**
     * Fan out deployments to all matching provider-bound domains owned by the provider's user.
     *
     * @return array{matched: int, dispatched: int, coalesced: int, skipped_cross_tenant: int}
     */
    public function dispatchMatchingPushDeployments(
        int $gitProviderId,
        int $providerUserId,
        string $remoteRepoId,
        string $branch,
        ?string $commitSha,
        ?string $commitMessage = null,
        ?string $commitAuthor = null,
    ): array;

    /**
     * Sync cached remote_repo_full_name across domain deployment configs bound to this repo ID.
     */
    public function syncRepositoryFullName(int $gitProviderId, string $remoteRepoId, string $fullName): int;
}
