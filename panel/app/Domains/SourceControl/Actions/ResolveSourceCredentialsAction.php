<?php

declare(strict_types=1);

namespace App\Domains\SourceControl\Actions;

use App\Domains\SourceControl\Contracts\GitProviderDriverInterface;
use App\Domains\SourceControl\Enums\GitProviderStatus;
use App\Domains\SourceControl\Models\GitProvider;
use App\Domains\SourceControl\ValueObjects\ResolvedFetchPayload;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ResolveSourceCredentialsAction
{
    public function __construct(
        private readonly GitProviderDriverInterface $driver,
    ) {}

    /**
     * Resolve execution-time credentials and immutable commit SHA for a provider-mode deployment.
     *
     * @throws AuthorizationException
     * @throws RuntimeException
     */
    public function execute(
        GitProvider $provider,
        int $domainOwnerUserId,
        string $remoteRepoId,
        string $remoteRepoFullName,
        string $branch,
        string $fetchDriver = 'provider_tarball',
        ?string $commitSha = null,
    ): ResolvedFetchPayload {
        if ((int) $provider->user_id !== $domainOwnerUserId) {
            Log::alert('Blocked provider-mode deployment due to domain/provider owner mismatch.', [
                'git_provider_id' => $provider->id,
                'provider_user_id' => $provider->user_id,
                'domain_user_id' => $domainOwnerUserId,
                'remote_repo_id' => $remoteRepoId,
            ]);

            throw new AuthorizationException(
                'Deployment aborted: domain owner does not match the bound Git provider owner.'
            );
        }

        if ($provider->status !== GitProviderStatus::Active) {
            throw new RuntimeException(
                "Git provider '{$provider->label}' is not active (current status: {$provider->status->value})."
            );
        }

        $repoId = trim($remoteRepoId);
        if ($repoId === '') {
            throw new RuntimeException('Provider-mode deployment requires a remote_repo_id.');
        }

        $fullName = trim($remoteRepoFullName);
        if (! str_contains($fullName, '/')) {
            throw new RuntimeException('Provider-mode deployment requires a valid owner/repo remote_repo_full_name.');
        }

        [$owner, $repo] = explode('/', $fullName, 2);
        $branchName = trim($branch) !== '' ? trim($branch) : 'main';

        $token = $this->driver->mintInstallationToken($provider, $repoId);

        $resolvedSha = $commitSha !== null && trim($commitSha) !== ''
            ? trim($commitSha)
            : $this->driver->resolveHeadCommitSha(
                provider: $provider,
                owner: $owner,
                repo: $repo,
                branch: $branchName,
                repositoryId: $repoId,
                token: $token,
            );

        $mode = in_array($fetchDriver, ['shallow_git', 'shallow_clone'], true)
            ? ResolvedFetchPayload::MODE_SHALLOW_CLONE
            : ResolvedFetchPayload::MODE_TARBALL;

        if ($mode === ResolvedFetchPayload::MODE_SHALLOW_CLONE) {
            $gitBaseUrl = $this->resolveGitWebBaseUrl($provider->base_url);

            return new ResolvedFetchPayload(
                mode: ResolvedFetchPayload::MODE_SHALLOW_CLONE,
                commitSha: $resolvedSha,
                branch: $branchName,
                cloneUrl: "{$gitBaseUrl}/{$fullName}.git",
                httpsUsername: 'x-access-token',
                httpsToken: $token,
            );
        }

        $apiBaseUrl = rtrim($provider->base_url ?: 'https://api.github.com', '/');

        return new ResolvedFetchPayload(
            mode: ResolvedFetchPayload::MODE_TARBALL,
            commitSha: $resolvedSha,
            branch: $branchName,
            tarballUrl: "{$apiBaseUrl}/repositories/{$repoId}/tarball/".rawurlencode($resolvedSha),
            authHeader: "Bearer {$token}",
        );
    }

    private function resolveGitWebBaseUrl(?string $apiBaseUrl): string
    {
        $normalized = rtrim($apiBaseUrl ?: 'https://api.github.com', '/');
        if ($normalized === 'https://api.github.com') {
            return 'https://github.com';
        }

        return preg_replace('#/api/v3$#i', '', $normalized) ?: 'https://github.com';
    }
}
