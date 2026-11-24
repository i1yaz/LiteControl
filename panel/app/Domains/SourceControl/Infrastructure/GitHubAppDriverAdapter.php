<?php

declare(strict_types=1);

namespace App\Domains\SourceControl\Infrastructure;

use App\Domains\SourceControl\Contracts\GitProviderDriverInterface;
use App\Domains\SourceControl\Enums\GitProviderStatus;
use App\Domains\SourceControl\Exceptions\GitProviderApiException;
use App\Domains\SourceControl\Models\GitProvider;
use App\Domains\SourceControl\ValueObjects\WebhookHealthResult;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GitHubAppDriverAdapter implements GitProviderDriverInterface
{
    private const API_VERSION = '2022-11-28';

    private const REPO_CACHE_TTL_SECONDS = 300;

    private const MAX_PAGINATION_PAGES = 50;

    private const JWT_IAT_BACKDATE_SECONDS = 60;

    private const JWT_EXP_LIFETIME_SECONDS = 540;

    public function convertManifest(string $code, string $baseUrl = 'https://api.github.com'): array
    {
        $url = rtrim($baseUrl, '/').'/app-manifests/'.rawurlencode($code).'/conversions';

        $response = $this->baseClient()->send('POST', $url);

        if (! $response->successful()) {
            $status = $response->status();
            $githubMessage = $response->json('message');
            $githubErrors = $response->json('errors');
            $responseBody = is_string($githubMessage) && trim($githubMessage) !== ''
                ? trim($githubMessage)
                : trim(substr($response->body(), 0, 500));

            $logContext = [
                'status_code' => $status,
                'github_message' => is_string($githubMessage) ? $githubMessage : null,
                'github_errors' => is_array($githubErrors) ? $githubErrors : null,
                'response_body' => $responseBody !== '' ? $responseBody : null,
            ];

            Log::warning('GitHub App manifest conversion failed.', $logContext);
            Log::channel('errorlog')->warning('GitHub App manifest conversion failed.', $logContext);

            $errorMessage = is_string($githubMessage) && trim($githubMessage) !== ''
                ? "GitHub App manifest conversion failed with HTTP {$status}: ".trim($githubMessage)
                : "GitHub App manifest conversion failed with HTTP {$status}.";

            throw new GitProviderApiException(
                message: $errorMessage,
                statusCode: $status,
            );
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new GitProviderApiException('GitHub App manifest conversion returned an invalid response payload.');
        }

        return $data;
    }

    public function getInstallation(GitProvider $provider, int $installationId, bool $markReauthOnFailure = true): array
    {
        $jwt = $this->generateAppJwt($provider);
        $url = $this->baseUrl($provider)."/app/installations/{$installationId}";

        $response = $this->baseClient()
            ->withToken($jwt)
            ->get($url);

        $this->assertResponseSuccessful($provider, $response, 'fetch GitHub App installation', $markReauthOnFailure);

        $data = $response->json();
        if (! is_array($data)) {
            throw new GitProviderApiException('GitHub App installation response was not a valid JSON object.');
        }

        return $data;
    }

    public function mintInstallationToken(GitProvider $provider, int|string $repositoryId): string
    {
        if (! $provider->installation_id) {
            throw new GitProviderApiException('Git provider does not have an active GitHub App installation ID.');
        }

        $jwt = $this->generateAppJwt($provider);
        $url = $this->baseUrl($provider)."/app/installations/{$provider->installation_id}/access_tokens";

        $response = $this->baseClient()
            ->withToken($jwt)
            ->post($url, [
                'repository_ids' => [(int) $repositoryId],
                'permissions' => [
                    'contents' => 'read',
                ],
            ]);

        $this->assertResponseSuccessful($provider, $response, 'mint scoped installation token');

        $token = $response->json('token');
        if (! is_string($token) || $token === '') {
            throw new GitProviderApiException('GitHub did not return an installation access token.');
        }

        return $token;
    }

    public function resolveHeadCommitSha(
        GitProvider $provider,
        string $owner,
        string $repo,
        string $branch,
        int|string|null $repositoryId = null,
        ?string $token = null,
    ): string {
        $resolvedToken = ($token !== null && $token !== '')
            ? $token
            : ($repositoryId !== null
                ? $this->mintInstallationToken($provider, $repositoryId)
                : $this->mintMetadataToken($provider, ['contents' => 'read', 'metadata' => 'read']));

        $encodedBranch = implode('/', array_map('rawurlencode', explode('/', $branch)));
        $url = $this->baseUrl($provider)
            .'/repos/'.rawurlencode($owner).'/'.rawurlencode($repo).'/commits/'.$encodedBranch;

        $response = $this->baseClient()
            ->withToken($resolvedToken)
            ->get($url);

        $this->assertResponseSuccessful($provider, $response, 'resolve branch head commit SHA');

        $sha = $response->json('sha');
        if (! is_string($sha) || trim($sha) === '') {
            throw new GitProviderApiException('GitHub did not return a valid commit SHA for the requested branch.');
        }

        return trim($sha);
    }

    public function listRepositories(GitProvider $provider, ?string $search = null, bool $forceRefresh = false): array
    {
        $cacheKey = self::reposCacheKey($provider->id);

        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        $repositories = $this->readEncryptedRepositoriesCache($cacheKey);

        if ($repositories === null) {
            $token = $this->mintMetadataToken($provider);
            $repositories = $this->fetchAllRepositories($provider, $token);
            $this->writeEncryptedRepositoriesCache($cacheKey, $repositories);
        }

        if ($search !== null && trim($search) !== '') {
            $needle = strtolower(trim($search));
            $repositories = array_values(array_filter(
                $repositories,
                static function (array $repo) use ($needle): bool {
                    $fullName = strtolower((string) ($repo['full_name'] ?? ''));
                    $name = strtolower((string) ($repo['name'] ?? ''));

                    return str_contains($fullName, $needle) || str_contains($name, $needle);
                }
            ));
        }

        return $repositories;
    }

    public function bustRepositoriesCache(GitProvider $provider): void
    {
        Cache::forget(self::reposCacheKey($provider->id));
    }

    public function listBranches(GitProvider $provider, string $owner, string $repo, int|string|null $repositoryId = null): array
    {
        $token = $repositoryId !== null
            ? $this->mintInstallationToken($provider, $repositoryId)
            : $this->mintMetadataToken($provider, ['contents' => 'read', 'metadata' => 'read']);

        $url = $this->baseUrl($provider)
            .'/repos/'.rawurlencode($owner).'/'.rawurlencode($repo).'/branches?per_page=100';

        $branches = [];
        $pageCount = 0;

        while ($url !== null && $pageCount < self::MAX_PAGINATION_PAGES) {
            $pageCount++;

            $response = $this->baseClient()
                ->withToken($token)
                ->get($url);

            $this->assertResponseSuccessful($provider, $response, 'list repository branches');

            $items = $response->json();
            if (is_array($items)) {
                foreach ($items as $branch) {
                    if (is_array($branch) && is_string($branch['name'] ?? null)) {
                        $branches[] = [
                            'name' => $branch['name'],
                            'protected' => (bool) ($branch['protected'] ?? false),
                            'sha' => is_string($branch['commit']['sha'] ?? null) ? $branch['commit']['sha'] : null,
                        ];
                    }
                }
            }

            $url = $this->parseNextLinkUrl($response->header('Link'));
        }

        return $branches;
    }

    public function repairWebhook(GitProvider $provider, string $webhookUrl): void
    {
        $jwt = $this->generateAppJwt($provider);
        $url = $this->baseUrl($provider).'/app/hook/config';

        $response = $this->baseClient()
            ->withToken($jwt)
            ->patch($url, [
                'url' => $webhookUrl,
                'content_type' => 'json',
                'insecure_ssl' => '0',
            ]);

        $this->assertResponseSuccessful($provider, $response, 'repair GitHub App webhook config');
    }

    public function getWebhookHealth(GitProvider $provider): WebhookHealthResult
    {
        $jwt = $this->generateAppJwt($provider);
        $url = $this->baseUrl($provider).'/app/hook/deliveries?per_page=5';

        $response = $this->baseClient()
            ->withToken($jwt)
            ->get($url);

        $this->assertResponseSuccessful($provider, $response, 'fetch GitHub App webhook deliveries');

        $deliveries = $response->json();
        if (! is_array($deliveries) || count($deliveries) === 0) {
            return new WebhookHealthResult(
                status: WebhookHealthResult::STATUS_NONE_YET,
                recentDeliveries: [],
            );
        }

        $normalized = [];
        foreach ($deliveries as $delivery) {
            if (! is_array($delivery)) {
                continue;
            }

            $normalized[] = [
                'id' => $delivery['id'] ?? null,
                'event' => is_string($delivery['event'] ?? null) ? $delivery['event'] : null,
                'status' => is_string($delivery['status'] ?? null) ? $delivery['status'] : null,
                'status_code' => is_numeric($delivery['status_code'] ?? null) ? (int) $delivery['status_code'] : null,
                'delivered_at' => is_string($delivery['delivered_at'] ?? null) ? $delivery['delivered_at'] : null,
            ];
        }

        if (count($normalized) === 0) {
            return new WebhookHealthResult(
                status: WebhookHealthResult::STATUS_NONE_YET,
                recentDeliveries: [],
            );
        }

        $latest = $normalized[0];
        $statusCode = $latest['status_code'];
        $statusText = $latest['status'];
        $deliveredAt = is_string($latest['delivered_at'])
            ? CarbonImmutable::parse($latest['delivered_at'])
            : null;

        $isHealthy = ($statusCode !== null && $statusCode >= 200 && $statusCode < 300)
            || ($statusCode === null && strtoupper((string) $statusText) === 'OK');

        return new WebhookHealthResult(
            status: $isHealthy ? WebhookHealthResult::STATUS_VERIFIED : WebhookHealthResult::STATUS_FAILING,
            lastStatusCode: $statusCode,
            lastDeliveredAt: $deliveredAt,
            recentDeliveries: $normalized,
        );
    }

    public static function reposCacheKey(int $providerId): string
    {
        return "git_provider:{$providerId}:repos";
    }

    public function generateAppJwt(GitProvider $provider): string
    {
        $credentials = $provider->safeCredentials();
        if ($credentials === null) {
            throw new GitProviderApiException('Git provider credentials could not be decrypted.');
        }

        if (! is_string($provider->external_app_id) || trim($provider->external_app_id) === '') {
            throw new GitProviderApiException('Git provider is missing external_app_id for JWT signing.');
        }

        $now = CarbonImmutable::now()->getTimestamp();

        $header = $this->base64UrlEncode((string) json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
        ], JSON_THROW_ON_ERROR));

        $payload = $this->base64UrlEncode((string) json_encode([
            'iat' => $now - self::JWT_IAT_BACKDATE_SECONDS,
            'exp' => $now + self::JWT_EXP_LIFETIME_SECONDS,
            'iss' => $provider->external_app_id,
        ], JSON_THROW_ON_ERROR));

        $signingInput = "{$header}.{$payload}";

        $privateKey = openssl_pkey_get_private($credentials->pem);
        if ($privateKey === false) {
            throw new RuntimeException('Failed to load RSA private key for GitHub App JWT signing.');
        }

        $signature = '';
        $signed = openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (! $signed) {
            throw new RuntimeException('Failed to sign GitHub App JWT with RS256.');
        }

        return $signingInput.'.'.$this->base64UrlEncode($signature);
    }

    /**
     * @param  array<string, string>  $permissions
     */
    private function mintMetadataToken(GitProvider $provider, array $permissions = ['metadata' => 'read']): string
    {
        if (! $provider->installation_id) {
            throw new GitProviderApiException('Git provider does not have an active GitHub App installation ID.');
        }

        $jwt = $this->generateAppJwt($provider);
        $url = $this->baseUrl($provider)."/app/installations/{$provider->installation_id}/access_tokens";

        $response = $this->baseClient()
            ->withToken($jwt)
            ->post($url, [
                'permissions' => $permissions,
            ]);

        $this->assertResponseSuccessful($provider, $response, 'mint installation metadata token');

        $token = $response->json('token');
        if (! is_string($token) || $token === '') {
            throw new GitProviderApiException('GitHub did not return an installation access token.');
        }

        return $token;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAllRepositories(GitProvider $provider, string $token): array
    {
        $url = $this->baseUrl($provider).'/installation/repositories?per_page=100';
        $repositories = [];
        $pageCount = 0;

        while ($url !== null && $pageCount < self::MAX_PAGINATION_PAGES) {
            $pageCount++;

            $response = $this->baseClient()
                ->withToken($token)
                ->get($url);

            $this->assertResponseSuccessful($provider, $response, 'list installation repositories');

            $items = $response->json('repositories');
            if (is_array($items)) {
                foreach ($items as $repo) {
                    if (! is_array($repo)) {
                        continue;
                    }

                    $repositories[] = [
                        'id' => (string) ($repo['id'] ?? ''),
                        'name' => (string) ($repo['name'] ?? ''),
                        'full_name' => (string) ($repo['full_name'] ?? ''),
                        'private' => (bool) ($repo['private'] ?? false),
                        'default_branch' => (string) ($repo['default_branch'] ?? 'main'),
                        'archived' => (bool) ($repo['archived'] ?? false),
                    ];
                }
            }

            $url = $this->parseNextLinkUrl($response->header('Link'));
        }

        return $repositories;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function readEncryptedRepositoriesCache(string $cacheKey): ?array
    {
        $encrypted = Cache::get($cacheKey);
        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            $json = Crypt::decryptString($encrypted);
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? array_values($decoded) : null;
        } catch (DecryptException|\JsonException) {
            Cache::forget($cacheKey);

            return null;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $repositories
     */
    private function writeEncryptedRepositoriesCache(string $cacheKey, array $repositories): void
    {
        $encrypted = Crypt::encryptString((string) json_encode($repositories, JSON_THROW_ON_ERROR));
        Cache::put($cacheKey, $encrypted, self::REPO_CACHE_TTL_SECONDS);
    }

    private function assertResponseSuccessful(
        GitProvider $provider,
        Response $response,
        string $operation,
        bool $markReauthOnFailure = true,
    ): void {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        $isReauthError = in_array($status, [401, 404], true);

        if ($isReauthError && $markReauthOnFailure) {
            $this->markReauthRequired($provider, $status, $operation);
        }

        Log::warning('GitHub API request failed.', [
            'git_provider_id' => $provider->id,
            'operation' => $operation,
            'status_code' => $status,
            'marked_reauth_required' => $isReauthError && $markReauthOnFailure,
        ]);

        throw new GitProviderApiException(
            message: "GitHub API failed to {$operation} (HTTP {$status}).",
            statusCode: $status,
            reauthRequired: $isReauthError && $markReauthOnFailure,
        );
    }

    private function markReauthRequired(GitProvider $provider, int $statusCode, string $operation): void
    {
        if (! $provider->exists || $provider->status === GitProviderStatus::ReauthRequired) {
            return;
        }

        $provider->forceFill([
            'status' => GitProviderStatus::ReauthRequired,
        ])->save();

        Log::warning('Marked GitProvider as reauth_required after GitHub API auth/not-found response.', [
            'git_provider_id' => $provider->id,
            'user_id' => $provider->user_id,
            'status_code' => $statusCode,
            'operation' => $operation,
        ]);
    }

    private function parseNextLinkUrl(?string $linkHeader): ?string
    {
        if (! is_string($linkHeader) || trim($linkHeader) === '') {
            return null;
        }

        if (preg_match('/<([^>]+)>;\s*rel="next"/i', $linkHeader, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function baseClient(): PendingRequest
    {
        return Http::timeout(15)
            ->accept('application/vnd.github+json')
            ->withHeaders([
                'X-GitHub-Api-Version' => self::API_VERSION,
                'User-Agent' => 'LiteControl-Panel',
            ]);
    }

    private function baseUrl(GitProvider $provider): string
    {
        return rtrim($provider->base_url ?: 'https://api.github.com', '/');
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
