<?php

declare(strict_types=1);

use App\Domains\Deployment\Models\DomainDeploymentConfig;
use App\Domains\Identity\Models\User;
use App\Domains\PanelConfig\Models\AuditLog;
use App\Domains\SourceControl\Contracts\SslCertificateInspectorInterface;
use App\Domains\SourceControl\Enums\GitProviderStatus;
use App\Domains\SourceControl\Enums\SourceMode;
use App\Domains\SourceControl\Models\GitProvider;
use App\Domains\SourceControl\ValueObjects\SslCertificateInspectionResult;
use App\Domains\WebServer\Models\Domain;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makePhase6Domain(User $user, string $domainName = 'app.example.com'): Domain
{
    return Domain::create([
        'user_id' => $user->id,
        'domain' => $domainName,
        'document_root' => "/home/{$user->username}/{$domainName}/public_html",
        'php_version' => '8.3',
    ]);
}

function makePhase6Provider(User $user, array $overrides = []): GitProvider
{
    return GitProvider::create(array_merge([
        'user_id' => $user->id,
        'provider' => 'github_app',
        'label' => 'Agency GitHub',
        'external_app_id' => (string) random_int(100000, 999999),
        'external_app_slug' => 'lc-panel-agency',
        'external_account_login' => 'acme-org',
        'external_account_type' => 'Organization',
        'installation_id' => random_int(10000, 99999),
        'credentials' => [
            'pem' => "-----BEGIN RSA PRIVATE KEY-----\nTEST_PEM_CONTENT\n-----END RSA PRIVATE KEY-----",
            'client_id' => 'Iv1.abcdef1234567890',
            'client_secret' => 'super-secret-client-secret-value',
        ],
        'webhook_secret' => 'super-secret-webhook-hmac-value',
        'configured_webhook_url' => 'https://panel.example.com/api/webhooks/git-providers/test',
        'status' => GitProviderStatus::Active,
    ], $overrides));
}

function bindTrustedPanelSslInspector(): void
{
    app()->instance(SslCertificateInspectorInterface::class, new class implements SslCertificateInspectorInterface
    {
        public function inspect(string $expectedHost, ?string $certPath = null): SslCertificateInspectionResult
        {
            return SslCertificateInspectionResult::found(
                selfSigned: false,
                trusted: true,
                validFrom: CarbonImmutable::now()->subDays(5),
                expiresAt: CarbonImmutable::now()->addDays(60),
                dnsNames: [$expectedHost],
                subject: "CN={$expectedHost}",
                issuer: 'CN=R10, O=Let\'s Encrypt, C=US',
            );
        }
    });
}

it('renders SourceControl/Index component with gate blocker and disables connect action when PanelUrlGate fails', function () {
    config()->set('app.url', 'http://192.168.1.10:8090');

    $user = User::factory()->create(['username' => 'scuser']);

    $this->actingAs($user)
        ->get('/settings/source-control')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('SourceControl/Index')
            ->where('gate.allowed', false)
            ->where('gate.code', 'not_https')
            ->where('canConnect', false)
            ->where('panelSslUrl', '/security/panel-ssl?return_to=/settings/source-control')
            ->has('providers', 0)
        );
});

it('saves provider binding on domain with auto_deploy defaulting to true', function () {
    Process::fake();

    $user = User::factory()->create(['username' => 'binduser']);
    $domain = makePhase6Domain($user, 'shop.example.com');
    $provider = makePhase6Provider($user);

    $this->actingAs($user)
        ->put("/domains/{$domain->id}/deploy/source-control", [
            'git_provider_id' => $provider->id,
            'remote_repo_id' => '987654321',
            'remote_repo_full_name' => 'acme-org/shop-app',
            'branch' => 'main',
            'preset' => 'laravel',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $config = DomainDeploymentConfig::where('domain_id', $domain->id)->firstOrFail();

    expect($config->source_mode)->toBe(SourceMode::Provider);
    expect($config->git_provider_id)->toBe($provider->id);
    expect($config->remote_repo_id)->toBe('987654321');
    expect($config->remote_repo_full_name)->toBe('acme-org/shop-app');
    expect($config->branch)->toBe('main');
    expect($config->auto_deploy)->toBeTrue();
    expect($config->fetch_driver)->toBe('provider_tarball');

    expect(
        AuditLog::where('user_id', $user->id)
            ->where('action', 'source_control.domain_bound')
            ->exists()
    )->toBeTrue();
});

it('clears manual PAT and SSH key when confirmed switch from manual to provider runs and logs audit event', function () {
    $user = User::factory()->create(['username' => 'switchuser']);
    $domain = makePhase6Domain($user, 'client.example.com');
    $provider = makePhase6Provider($user);

    $config = DomainDeploymentConfig::create([
        'domain_id' => $domain->id,
        'mode' => 'atomic',
        'source_mode' => SourceMode::Manual,
        'repo_provider' => 'github',
        'repo_url' => 'git@github.com:client/repo.git',
        'branch' => 'main',
        'fetch_driver' => 'shallow_git',
        'api_token_encrypted' => 'ghp_manual_secret_pat_token',
        'deploy_key_public' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAITEST deploy@client.example.com',
        'deploy_key_private_encrypted' => "-----BEGIN OPENSSH PRIVATE KEY-----\nMANUAL_KEY\n-----END OPENSSH PRIVATE KEY-----",
        'webhook_token' => Str::random(40),
        'webhook_secret_encrypted' => 'manual-webhook-secret',
    ]);

    // Unconfirmed switch must be rejected so credentials are not silently wiped
    $this->actingAs($user)
        ->post("/domains/{$domain->id}/deploy/source-mode", [
            'target_mode' => 'provider',
            'confirm_clear_credentials' => false,
            'git_provider_id' => $provider->id,
        ])
        ->assertSessionHasErrors('confirm_clear_credentials');

    $config->refresh();
    expect($config->source_mode)->toBe(SourceMode::Manual);
    expect($config->deploy_key_public)->not->toBeNull();

    // Confirmed switch clears manual PAT, SSH keys, and manual webhook secret, and logs audit event
    $this->actingAs($user)
        ->post("/domains/{$domain->id}/deploy/source-mode", [
            'target_mode' => 'provider',
            'confirm_clear_credentials' => true,
            'git_provider_id' => $provider->id,
            'remote_repo_id' => '555111222',
            'remote_repo_full_name' => 'acme-org/client-app',
            'branch' => 'production',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $config->refresh();

    expect($config->source_mode)->toBe(SourceMode::Provider);
    expect($config->git_provider_id)->toBe($provider->id);
    expect($config->auto_deploy)->toBeTrue();
    expect($config->getRawOriginal('api_token_encrypted'))->toBeNull();
    expect($config->deploy_key_public)->toBeNull();
    expect($config->getRawOriginal('deploy_key_private_encrypted'))->toBeNull();
    expect($config->getRawOriginal('webhook_secret_encrypted'))->toBeNull();

    $audit = AuditLog::where('user_id', $user->id)
        ->where('action', 'source_control.switched_to_provider')
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit->details['manual_credentials_cleared'] ?? false)->toBeTrue();
});

it('blocks disconnecting a provider while domains remain linked', function () {
    $user = User::factory()->create(['username' => 'discuser']);
    $domain = makePhase6Domain($user, 'linked.example.com');
    $provider = makePhase6Provider($user);

    DomainDeploymentConfig::create([
        'domain_id' => $domain->id,
        'mode' => 'atomic',
        'source_mode' => SourceMode::Provider,
        'git_provider_id' => $provider->id,
        'remote_repo_id' => '112233',
        'remote_repo_full_name' => 'acme-org/linked-app',
        'branch' => 'main',
        'auto_deploy' => true,
        'webhook_token' => Str::random(40),
    ]);

    // Blocked when switch_linked_to_manual is not enabled
    $this->actingAs($user)
        ->delete("/settings/source-control/providers/{$provider->id}")
        ->assertSessionHasErrors('provider');

    expect(GitProvider::whereKey($provider->id)->exists())->toBeTrue();

    // Succeeds when switch_linked_to_manual is explicitly confirmed
    $this->actingAs($user)
        ->delete("/settings/source-control/providers/{$provider->id}", [
            'switch_linked_to_manual' => true,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(GitProvider::whereKey($provider->id)->exists())->toBeFalse();

    $config = DomainDeploymentConfig::where('domain_id', $domain->id)->firstOrFail();
    expect($config->source_mode)->toBe(SourceMode::Manual);
    expect($config->git_provider_id)->toBeNull();
    expect($config->auto_deploy)->toBeFalse();
});

it('renders existing manual domain deploy page with zero regressions', function () {
    config()->set('app.url', 'https://panel.example.com');
    bindTrustedPanelSslInspector();

    $user = User::factory()->create(['username' => 'manualuser']);
    $domain = makePhase6Domain($user, 'manual.example.com');

    DomainDeploymentConfig::create([
        'domain_id' => $domain->id,
        'mode' => 'atomic',
        'source_mode' => SourceMode::Manual,
        'repo_provider' => 'github',
        'repo_url' => 'git@github.com:legacy/site.git',
        'branch' => 'main',
        'fetch_driver' => 'shallow_git',
        'deploy_key_public' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAILEGACY deploy@manual.example.com',
        'webhook_token' => Str::random(40),
    ]);

    $this->actingAs($user)
        ->get("https://panel.example.com/domains/{$domain->id}/deploy")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Domains/Deployment/Index')
            ->where('domain.id', $domain->id)
            ->where('config.mode', 'atomic')
            ->where('config.repo_url', 'git@github.com:legacy/site.git')
            ->where('sourceControl.source_mode', 'manual')
            ->where('sourceControl.is_configured', true)
            ->where('sourceControl.has_deploy_key', true)
            ->where('sourceControl.gate.allowed', true)
        );
});
