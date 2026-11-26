<?php

declare(strict_types=1);

namespace App\Domains\SourceControl\Contracts;

use App\Domains\SourceControl\Models\GitProvider;
use App\Domains\SourceControl\ValueObjects\WebhookHealthResult;

interface GitProviderDriverInterface
{
    /**
     * Convert a GitHub App manifest temporary code into credentials and app metadata.
     *
     * @return array<string, mixed>
     */
    public function convertManifest(string $code, string $baseUrl = 'https://api.github.com'): array;

    /**
     * Fetch installation details authenticated with the provider's App JWT.
     *
     * @return array<string, mixed>
     */
    public function getInstallation(GitProvider $provider, int $installationId, bool $markReauthOnFailure = true): array;

    /**
     * Mint a short-lived installation access token scoped strictly to a single repository with contents:read.
     */
    public function mintInstallationToken(GitProvider $provider, int|string $repositoryId): string;

    /**
     * Resolve the immutable HEAD commit SHA for a repository branch via GET /repos/{owner}/{repo}/commits/{branch}.
     */
    public function resolveHeadCommitSha(
        GitProvider $provider,
        string $owner,
        string $repo,
        string $branch,
        int|string|null $repositoryId = null,
        ?string $token = null,
    ): string;

    /**
     * List repositories accessible to the installation with encrypted 5-minute caching and local search filtering.
     *
     * @return list<array<string, mixed>>
     */
    public function listRepositories(GitProvider $provider, ?string $search = null, bool $forceRefresh = false): array;

    /**
     * Bust the cached repository list for the given provider.
     */
    public function bustRepositoriesCache(GitProvider $provider): void;

    /**
     * List branches for a repository with full pagination.
     *
     * @return list<array<string, mixed>>
     */
    public function listBranches(GitProvider $provider, string $owner, string $repo, int|string|null $repositoryId = null): array;

    /**
     * Update the GitHub App's webhook target URL via PATCH /app/hook/config.
     */
    public function repairWebhook(GitProvider $provider, string $webhookUrl): void;

    /**
     * Fetch recent webhook deliveries from GET /app/hook/deliveries?per_page=5 and classify health.
     */
    public function getWebhookHealth(GitProvider $provider): WebhookHealthResult;
}
