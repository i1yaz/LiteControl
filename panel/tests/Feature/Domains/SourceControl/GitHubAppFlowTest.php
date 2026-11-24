<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\PanelConfig\Models\AuditLog;
use App\Domains\SourceControl\Actions\CompleteGitHubAppManifestAction;
use App\Domains\SourceControl\Actions\InitiateGitHubAppManifestAction;
use App\Domains\SourceControl\Contracts\GitProviderDriverInterface;
use App\Domains\SourceControl\Contracts\SslCertificateInspectorInterface;
use App\Domains\SourceControl\Enums\GitProviderStatus;
use App\Domains\SourceControl\Exceptions\GitProviderApiException;
use App\Domains\SourceControl\Infrastructure\GitHubAppDriverAdapter;
use App\Domains\SourceControl\Models\GitProvider;
use App\Domains\SourceControl\ValueObjects\SslCertificateInspectionResult;
use App\Domains\SourceControl\ValueObjects\WebhookHealthResult;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function githubTestRsaPem(): string
{
    static $pem = null;

    if ($pem === null) {
        $baseConfig = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $usedConfig = $baseConfig;
        $resource = @openssl_pkey_new($usedConfig);
        $exported = '';

        if ($resource !== false && @openssl_pkey_export($resource, $exported, null, $usedConfig) && $exported !== '') {
            $pem = $exported;

            return $pem;
        }

        // Windows PHP builds without OPENSSL_CONF require an explicit openssl.cnf path for both new & export.
        $tempCnf = tempnam(sys_get_temp_dir(), 'lc_openssl_');
        if ($tempCnf === false) {
            throw new RuntimeException('Unable to create temporary openssl.cnf for GitHub App tests.');
        }

        file_put_contents($tempCnf, "[req]\ndistinguished_name=dn\n[dn]\n");

        try {
            $usedConfig = $baseConfig + ['config' => $tempCnf];
            $resource = openssl_pkey_new($usedConfig);
            if ($resource === false || ! openssl_pkey_export($resource, $exported, null, $usedConfig) || $exported === '') {
                throw new RuntimeException('Unable to generate RSA keypair for GitHub App tests.');
            }
            $pem = $exported;
        } finally {
            @unlink($tempCnf);
        }
    }

    return $pem;
}

function bindPanelUrlGateSslResult(SslCertificateInspectionResult $result): void
{
    app()->bind(
        SslCertificateInspectorInterface::class,
        static fn () => new class($result) implements SslCertificateInspectorInterface
        {
            public function __construct(
                private readonly SslCertificateInspectionResult $result,
            ) {}

            public function inspect(string $expectedHost, ?string $certPath = null): SslCertificateInspectionResult
            {
                return $this->result;
            }
        }
    );
}

function bindPassingPanelUrlGate(string $host = 'panel.example.com', string $url = 'https://panel.example.com:8090'): void
{
    config()->set('app.url', $url);

    bindPanelUrlGateSslResult(SslCertificateInspectionResult::found(
        selfSigned: false,
        trusted: true,
        validFrom: CarbonImmutable::now()->subDays(10),
        expiresAt: CarbonImmutable::now()->addDays(60),
        dnsNames: [$host],
        subject: "CN={$host}",
        issuer: 'CN=R10, O=Let\'s Encrypt, C=US',
    ));
}

function createGitHubTestUser(?string $username = null): User
{
    static $seq = 1;
    $handle = $username ?? 'ghuser'.$seq++;

    return User::create([
        'name' => 'Test User '.$handle,
        'username' => $handle,
        'email' => "{$handle}@example.test",
        'password' => 'password',
        'role' => 'user',
    ]);
}

function createGitHubTestProvider(User $user, array $overrides = []): GitProvider
{
    return GitProvider::create(array_merge([
        'user_id' => $user->id,
        'provider' => 'github_app',
        'label' => 'My GitHub App',
        'base_url' => 'https://api.github.com',
        'external_app_id' => '123456',
        'external_app_slug' => 'lc-panel-abc123',
        'external_account_login' => 'octocat',
        'external_account_type' => 'User',
        'installation_id' => 987654,
        'repository_selection' => 'selected',
        'credentials' => [
            'pem' => githubTestRsaPem(),
            'client_id' => 'Iv1.testclientid',
            'client_secret' => 'test-client-secret',
        ],
        'webhook_secret' => 'whsec_test_secret_value',
        'configured_webhook_url' => 'https://panel.example.com:8090/api/webhooks/git-providers/initial',
        'status' => GitProviderStatus::Active,
    ], $overrides));
}

it('binds GitProviderDriverInterface to GitHubAppDriverAdapter and enforces SameSite=Lax session config', function (): void {
    expect(app(GitProviderDriverInterface::class))
        ->toBeInstanceOf(GitHubAppDriverAdapter::class)
        ->and(config('session.same_site'))
        ->toBe('lax');
});

it('blocks manifest initiation when PanelUrlGate fails', function (): void {
    $user = createGitHubTestUser();
    config()->set('app.url', 'http://panel.example.com:8090');

    $response = $this->actingAs($user)
        ->postJson('http://panel.example.com:8090/settings/source-control/github/manifest', [
            'label' => 'Work GitHub',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['panel_url']);
});

it('rejects open-redirect return_to values and invalid org_name strings', function (): void {
    $user = createGitHubTestUser();
    bindPassingPanelUrlGate();

    $this->actingAs($user)
        ->postJson('https://panel.example.com:8090/settings/source-control/github/manifest', [
            'return_to' => 'https://evil.com/steal',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['return_to']);

    $this->actingAs($user)
        ->postJson('https://panel.example.com:8090/settings/source-control/github/manifest', [
            'return_to' => '//evil.com/path',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['return_to']);

    $this->actingAs($user)
        ->postJson('https://panel.example.com:8090/settings/source-control/github/manifest', [
            'org_name' => 'bad/org name!',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['org_name']);
});

it('truncates generated GitHub App names to 34 characters or fewer and builds manifest from configured URL', function (): void {
    $user = createGitHubTestUser();
    $longHost = 'very-long-panel-subdomain.production.eu-west-1.example.com';
    bindPassingPanelUrlGate($longHost, "https://{$longHost}:8090");

    $response = $this->actingAs($user)
        ->postJson("https://{$longHost}:8090/settings/source-control/github/manifest", [
            'label' => 'Org App',
            'org_name' => 'acme-org',
            'return_to' => '/domains/5/deploy',
        ]);

    $response->assertOk();

    $data = $response->json();
    expect(strlen($data['manifest']['name']))->toBeLessThanOrEqual(34)
        ->and($data['manifest']['url'])->toBe("https://{$longHost}:8090")
        ->and($data['manifest']['redirect_url'])->toBe("https://{$longHost}:8090/settings/source-control/github/callback")
        ->and($data['manifest']['setup_url'])->toBe("https://{$longHost}:8090/settings/source-control/github/setup")
        ->and($data['manifest']['hook_attributes']['url'])->toBe("https://{$longHost}:8090/api/webhooks/git-providers/{$data['provider_uuid']}")
        ->and($data['manifest']['public'])->toBeFalse()
        ->and($data['manifest']['setup_on_update'])->toBeTrue()
        ->and($data['manifest']['default_permissions'])->toBe(['contents' => 'read', 'metadata' => 'read'])
        ->and($data['manifest']['default_events'])->toBe(['push', 'repository'])
        ->and($data['action_url'])->toStartWith('https://github.com/organizations/acme-org/settings/apps/new?state=');
});

it('converts manifest code, persists provider before consuming state, and sets status pending_installation', function (): void {
    $user = createGitHubTestUser();
    bindPassingPanelUrlGate();

    $init = $this->actingAs($user)
        ->postJson('https://panel.example.com:8090/settings/source-control/github/manifest', [
            'label' => 'Personal GitHub',
            'return_to' => '/domains/10/deploy',
        ])
        ->assertOk()
        ->json();

    $state = $init['state'];
    $providerUuid = $init['provider_uuid'];

    Http::fake([
        'https://api.github.com/app-manifests/temp-code-123/conversions' => Http::response([
            'id' => 777001,
            'slug' => 'lc-panel-abc123',
            'name' => 'lc-panel-abc123',
            'pem' => githubTestRsaPem(),
            'client_id' => 'Iv1.abcdef',
            'client_secret' => 'super-secret-client',
            'webhook_secret' => 'super-secret-webhook',
            'owner' => [
                'login' => 'octocat',
                'type' => 'User',
            ],
        ], 201),
    ]);

    $response = $this->actingAs($user)
        ->get("https://panel.example.com:8090/settings/source-control/github/callback?code=temp-code-123&state={$state}");

    $response->assertRedirect();
    expect($response->headers->get('Location'))
        ->toStartWith('https://github.com/apps/lc-panel-abc123/installations/new?state=');

    $provider = GitProvider::query()->where('uuid', $providerUuid)->first();
    expect($provider)->not->toBeNull()
        ->and($provider->user_id)->toBe($user->id)
        ->and($provider->label)->toBe('Personal GitHub')
        ->and($provider->external_app_id)->toBe('777001')
        ->and($provider->external_app_slug)->toBe('lc-panel-abc123')
        ->and($provider->status)->toBe(GitProviderStatus::PendingInstallation)
        ->and($provider->configured_webhook_url)->toBe("https://panel.example.com:8090/api/webhooks/git-providers/{$providerUuid}")
        ->and($provider->safeCredentials()?->clientId)->toBe('Iv1.abcdef')
        ->and($provider->safeWebhookSecret())->toBe('super-secret-webhook');

    // One-time state must be consumed only after persistence succeeds
    expect(Cache::get(InitiateGitHubAppManifestAction::STATE_CACHE_PREFIX.$state))->toBeNull();

    expect(AuditLog::query()->where('action', 'source_control.github_app_created')->exists())->toBeTrue();
});

it('shows explicit session-expiry recovery prompt when callback arrives without authenticated session', function (): void {
    $response = $this->get('https://panel.example.com:8090/settings/source-control/github/callback?code=abc&state=xyz');

    $response->assertRedirect(route('login'))
        ->assertSessionHas('error');
});

it('preserves state and surfaces GitHub App deletion URL if DB persistence fails after conversion', function (): void {
    $user = createGitHubTestUser();
    bindPassingPanelUrlGate();

    // Seed existing provider with same (user_id, external_app_id) to trigger unique constraint failure
    createGitHubTestProvider($user, [
        'external_app_id' => '999888',
        'external_app_slug' => 'existing-app',
    ]);

    $state = 'manifest-state-collision';
    Cache::put(InitiateGitHubAppManifestAction::STATE_CACHE_PREFIX.$state, [
        'user_id' => $user->id,
        'provider_uuid' => '11111111-1111-4111-8111-111111111111',
        'label' => 'Duplicate App',
        'return_to' => '/settings/source-control',
        'configured_webhook_url' => 'https://panel.example.com:8090/api/webhooks/git-providers/11111111-1111-4111-8111-111111111111',
    ], 1800);

    Http::fake([
        'https://api.github.com/app-manifests/dup-code/conversions' => Http::response([
            'id' => 999888,
            'slug' => 'orphaned-lc-app',
            'name' => 'orphaned-lc-app',
            'pem' => githubTestRsaPem(),
            'client_id' => 'Iv1.dup',
            'client_secret' => 'secret-dup',
            'webhook_secret' => 'whsec-dup',
            'owner' => ['login' => 'octocat', 'type' => 'User'],
        ], 201),
    ]);

    $response = $this->actingAs($user)
        ->get("https://panel.example.com:8090/settings/source-control/github/callback?code=dup-code&state={$state}");

    $response->assertRedirect('/settings/source-control')
        ->assertSessionHas('orphaned_github_app_deletion_url', 'https://github.com/settings/apps/orphaned-lc-app/advanced');

    // State was NOT consumed because DB persistence failed
    expect(Cache::get(InitiateGitHubAppManifestAction::STATE_CACHE_PREFIX.$state))->not->toBeNull();
});

it('handles setup callback with valid state and activates provider', function (): void {
    $user = createGitHubTestUser();
    $provider = createGitHubTestProvider($user, [
        'installation_id' => null,
        'status' => GitProviderStatus::PendingInstallation,
    ]);

    $installState = 'valid-install-state-xyz';
    Cache::put(CompleteGitHubAppManifestAction::INSTALL_STATE_CACHE_PREFIX.$installState, [
        'user_id' => $user->id,
        'git_provider_id' => $provider->id,
        'return_to' => '/domains/7/deploy',
    ], 1800);

    Http::fake([
        'https://api.github.com/app/installations/555123' => Http::response([
            'id' => 555123,
            'repository_selection' => 'selected',
            'account' => [
                'login' => 'acme-org',
                'type' => 'Organization',
            ],
        ], 200),
    ]);

    $response = $this->actingAs($user)
        ->get("https://panel.example.com:8090/settings/source-control/github/setup?installation_id=555123&state={$installState}&setup_action=install");

    $response->assertRedirect('/domains/7/deploy');

    $provider->refresh();
    expect($provider->installation_id)->toBe(555123)
        ->and($provider->external_account_login)->toBe('acme-org')
        ->and($provider->external_account_type)->toBe('Organization')
        ->and($provider->repository_selection)->toBe('selected')
        ->and($provider->status)->toBe(GitProviderStatus::Active)
        ->and($provider->suspended_at)->toBeNull()
        ->and(Cache::get(CompleteGitHubAppManifestAction::INSTALL_STATE_CACHE_PREFIX.$installState))->toBeNull()
        ->and(AuditLog::query()->where('action', 'source_control.github_app_installed')->exists())->toBeTrue();
});

it('handles setup callback WITHOUT state (setup_on_update flow) by verifying installation_id via JWT', function (): void {
    $user = createGitHubTestUser();

    $activeProvider = createGitHubTestProvider($user, [
        'external_app_id' => '111111',
        'external_app_slug' => 'active-app',
        'installation_id' => 1001,
        'status' => GitProviderStatus::Active,
    ]);

    $pendingProvider = createGitHubTestProvider($user, [
        'external_app_id' => '222222',
        'external_app_slug' => 'pending-app',
        'installation_id' => null,
        'status' => GitProviderStatus::PendingInstallation,
    ]);

    Http::fake(function (HttpClientRequest $request) {
        $auth = $request->header('Authorization')[0] ?? '';
        $jwt = str_replace('Bearer ', '', $auth);
        $parts = explode('.', $jwt);
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        if (($payload['iss'] ?? null) === '222222') {
            return Http::response([
                'id' => 777888,
                'repository_selection' => 'all',
                'account' => ['login' => 'updated-owner', 'type' => 'User'],
            ], 200);
        }

        return Http::response(['message' => 'Not Found'], 404);
    });

    $response = $this->actingAs($user)
        ->get('https://panel.example.com:8090/settings/source-control/github/setup?installation_id=777888&setup_action=update');

    $response->assertRedirect('/settings/source-control');

    $pendingProvider->refresh();
    $activeProvider->refresh();

    expect($pendingProvider->installation_id)->toBe(777888)
        ->and($pendingProvider->status)->toBe(GitProviderStatus::Active)
        ->and($pendingProvider->repository_selection)->toBe('all')
        ->and($activeProvider->status)->toBe(GitProviderStatus::Active);
});

it('rejects forged installation_id that fails JWT verification (403)', function (): void {
    $user = createGitHubTestUser();
    $provider = createGitHubTestProvider($user, [
        'installation_id' => null,
        'status' => GitProviderStatus::PendingInstallation,
    ]);

    Http::fake([
        'https://api.github.com/app/installations/666999' => Http::response(['message' => 'Not Found'], 404),
    ]);

    $this->actingAs($user)
        ->get('https://panel.example.com:8090/settings/source-control/github/setup?installation_id=666999')
        ->assertForbidden();

    expect($provider->fresh()->installation_id)->toBeNull()
        ->and($provider->fresh()->status)->toBe(GitProviderStatus::PendingInstallation);
});

it('paginates repository listing, encrypts cached results, and filters locally', function (): void {
    $user = createGitHubTestUser();
    $provider = createGitHubTestProvider($user);

    Http::fake([
        'https://api.github.com/app/installations/987654/access_tokens' => Http::response([
            'token' => 'ghs_metadata_token_secret',
        ], 201),
        'https://api.github.com/installation/repositories?per_page=100' => Http::response([
            'repositories' => [
                ['id' => 101, 'name' => 'alpha-site', 'full_name' => 'octocat/alpha-site', 'private' => true, 'default_branch' => 'main', 'archived' => false],
            ],
        ], 200, [
            'Link' => '<https://api.github.com/installation/repositories?per_page=100&page=2>; rel="next"',
        ]),
        'https://api.github.com/installation/repositories?per_page=100&page=2' => Http::response([
            'repositories' => [
                ['id' => 202, 'name' => 'beta-api', 'full_name' => 'octocat/beta-api', 'private' => false, 'default_branch' => 'develop', 'archived' => false],
            ],
        ], 200),
    ]);

    /** @var GitProviderDriverInterface $driver */
    $driver = app(GitProviderDriverInterface::class);

    $allRepos = $driver->listRepositories($provider);
    expect($allRepos)->toHaveCount(2)
        ->and($allRepos[0]['full_name'])->toBe('octocat/alpha-site')
        ->and($allRepos[1]['full_name'])->toBe('octocat/beta-api');

    // Verify cached value is encrypted at rest (does not contain plaintext repo names)
    $rawCached = Cache::get(GitHubAppDriverAdapter::reposCacheKey($provider->id));
    expect($rawCached)->toBeString()
        ->and($rawCached)->not->toContain('alpha-site')
        ->and(json_decode(Crypt::decryptString($rawCached), true)[0]['full_name'])->toBe('octocat/alpha-site');

    // Local search uses cache without making additional HTTP calls
    $filtered = $driver->listRepositories($provider, search: 'beta');
    expect($filtered)->toHaveCount(1)
        ->and($filtered[0]['id'])->toBe('202');

    Http::assertSentCount(3);
});

it('mints installation token scoped to single repository_id with contents read and backdated RS256 JWT', function (): void {
    $user = createGitHubTestUser();
    $provider = createGitHubTestProvider($user, [
        'external_app_id' => '424242',
        'installation_id' => 987654,
    ]);

    $capturedJwtPayload = null;
    $capturedBody = null;

    Http::fake(function (HttpClientRequest $request) use (&$capturedJwtPayload, &$capturedBody) {
        $auth = $request->header('Authorization')[0] ?? '';
        $jwt = str_replace('Bearer ', '', $auth);
        $parts = explode('.', $jwt);
        $capturedJwtPayload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        $capturedBody = $request->data();

        return Http::response([
            'token' => 'ghs_scoped_token_12345',
        ], 201);
    });

    /** @var GitProviderDriverInterface $driver */
    $driver = app(GitProviderDriverInterface::class);
    $token = $driver->mintInstallationToken($provider, '998877');

    $now = CarbonImmutable::now()->getTimestamp();

    expect($token)->toBe('ghs_scoped_token_12345')
        ->and($capturedBody)->toBe([
            'repository_ids' => [998877],
            'permissions' => ['contents' => 'read'],
        ])
        ->and($capturedJwtPayload['iss'])->toBe('424242')
        ->and($capturedJwtPayload['iat'])->toBeLessThanOrEqual($now - 55)
        ->and($capturedJwtPayload['exp'] - $capturedJwtPayload['iat'])->toBe(600);
});

it('marks reauth_required on 401/404 but NOT on 429, 500, or secondary rate-limit 403', function (int $statusCode, bool $shouldMarkReauth): void {
    $user = createGitHubTestUser();
    $provider = createGitHubTestProvider($user, [
        'status' => GitProviderStatus::Active,
    ]);

    Http::fake([
        'https://api.github.com/app/installations/987654/access_tokens' => Http::response(['message' => 'error'], $statusCode),
    ]);

    /** @var GitProviderDriverInterface $driver */
    $driver = app(GitProviderDriverInterface::class);

    expect(fn () => $driver->mintInstallationToken($provider, 12345))
        ->toThrow(GitProviderApiException::class);

    $expectedStatus = $shouldMarkReauth ? GitProviderStatus::ReauthRequired : GitProviderStatus::Active;
    expect($provider->fresh()->status)->toBe($expectedStatus);
})->with([
    '401 unauthorized' => [401, true],
    '404 deleted installation' => [404, true],
    '403 secondary rate limit' => [403, false],
    '429 rate limited' => [429, false],
    '500 server error' => [500, false],
]);

it('updates webhook URL via PATCH /app/hook/config and reads health from /app/hook/deliveries', function (): void {
    $user = createGitHubTestUser();
    bindPassingPanelUrlGate('newpanel.example.com', 'https://newpanel.example.com:8090');

    $provider = createGitHubTestProvider($user, [
        'configured_webhook_url' => 'https://oldpanel.example.com:8090/api/webhooks/git-providers/old',
    ]);

    Http::fake([
        'https://api.github.com/app/hook/config' => Http::response([
            'url' => "https://newpanel.example.com:8090/api/webhooks/git-providers/{$provider->uuid}",
        ], 200),
        'https://api.github.com/app/hook/deliveries?per_page=5' => Http::response([
            [
                'id' => 9001,
                'event' => 'push',
                'status' => 'OK',
                'status_code' => 200,
                'delivered_at' => '2026-11-25T00:00:00Z',
            ],
        ], 200),
    ]);

    $this->actingAs($user)
        ->postJson("https://newpanel.example.com:8090/settings/source-control/providers/{$provider->id}/repair-webhook")
        ->assertOk()
        ->assertJson([
            'configured_webhook_url' => "https://newpanel.example.com:8090/api/webhooks/git-providers/{$provider->uuid}",
        ]);

    Http::assertSent(function (HttpClientRequest $request) use ($provider): bool {
        return $request->method() === 'PATCH'
            && $request->url() === 'https://api.github.com/app/hook/config'
            && $request['url'] === "https://newpanel.example.com:8090/api/webhooks/git-providers/{$provider->uuid}";
    });

    expect($provider->fresh()->configured_webhook_url)
        ->toBe("https://newpanel.example.com:8090/api/webhooks/git-providers/{$provider->uuid}")
        ->and(AuditLog::query()->where('action', 'source_control.webhook_repaired')->exists())->toBeTrue();

    $this->actingAs($user)
        ->getJson("https://newpanel.example.com:8090/settings/source-control/providers/{$provider->id}/health")
        ->assertOk()
        ->assertJson([
            'status' => WebhookHealthResult::STATUS_VERIFIED,
            'last_status_code' => 200,
        ]);
});

it('prunes abandoned pending_installation providers older than 7 days only', function (): void {
    $user = createGitHubTestUser();

    $abandoned = createGitHubTestProvider($user, [
        'external_app_id' => '10001',
        'installation_id' => null,
        'status' => GitProviderStatus::PendingInstallation,
    ]);
    GitProvider::query()->whereKey($abandoned->id)->update(['created_at' => CarbonImmutable::now()->subDays(9)]);

    $recentPending = createGitHubTestProvider($user, [
        'external_app_id' => '10002',
        'installation_id' => null,
        'status' => GitProviderStatus::PendingInstallation,
    ]);
    GitProvider::query()->whereKey($recentPending->id)->update(['created_at' => CarbonImmutable::now()->subDays(2)]);

    $oldActive = createGitHubTestProvider($user, [
        'external_app_id' => '10003',
        'installation_id' => 555000,
        'status' => GitProviderStatus::Active,
    ]);
    GitProvider::query()->whereKey($oldActive->id)->update(['created_at' => CarbonImmutable::now()->subDays(30)]);

    $this->artisan('source-control:prune-abandoned-providers')
        ->assertSuccessful();

    expect(GitProvider::query()->find($abandoned->id))->toBeNull()
        ->and(GitProvider::query()->find($recentPending->id))->not->toBeNull()
        ->and(GitProvider::query()->find($oldActive->id))->not->toBeNull();
});

it('forbids another user from repairing webhook or viewing health of a provider (403)', function (): void {
    $owner = createGitHubTestUser('owner1');
    $other = createGitHubTestUser('other2');
    bindPassingPanelUrlGate();

    $provider = createGitHubTestProvider($owner);

    $this->actingAs($other)
        ->postJson("https://panel.example.com:8090/settings/source-control/providers/{$provider->id}/repair-webhook")
        ->assertForbidden();

    $this->actingAs($other)
        ->getJson("https://panel.example.com:8090/settings/source-control/providers/{$provider->id}/health")
        ->assertForbidden();
});

it('enforces per-user git provider cap during manifest initiation', function (): void {
    config()->set('litecontrol.max_git_providers_per_user', 1);
    $user = createGitHubTestUser();
    bindPassingPanelUrlGate();

    createGitHubTestProvider($user);

    $this->actingAs($user)
        ->postJson('https://panel.example.com:8090/settings/source-control/github/manifest', [
            'label' => 'Second App',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['provider']);
});
