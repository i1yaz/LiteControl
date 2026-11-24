<?php

declare(strict_types=1);

use App\Domains\Deployment\Models\DomainDeploymentConfig;
use App\Domains\Identity\Models\User;
use App\Domains\PanelConfig\Models\AuditLog;
use App\Domains\SourceControl\Enums\GitProviderStatus;
use App\Domains\SourceControl\Enums\SourceMode;
use App\Domains\SourceControl\Models\GitProvider;
use App\Domains\SourceControl\ValueObjects\ProviderCredentials;
use App\Domains\WebServer\Actions\TransferDomainOwnershipAction;
use App\Domains\WebServer\Models\Domain;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeTestDomain(User $user, string $domainName = 'example.com'): Domain
{
    return Domain::create([
        'user_id' => $user->id,
        'domain' => $domainName,
        'document_root' => "/home/{$user->username}/{$domainName}/public_html",
        'php_version' => '8.3',
    ]);
}

function makeTestGitProvider(User $user, array $overrides = []): GitProvider
{
    return GitProvider::create(array_merge([
        'user_id' => $user->id,
        'provider' => 'github_app',
        'label' => 'Primary GitHub App',
        'external_app_id' => (string) random_int(100000, 999999),
        'external_app_slug' => 'lc-panel-test',
        'credentials' => [
            'pem' => "-----BEGIN RSA PRIVATE KEY-----\nTEST_PEM_CONTENT\n-----END RSA PRIVATE KEY-----",
            'client_id' => 'Iv1.abcdef1234567890',
            'client_secret' => 'super-secret-client-secret-value',
        ],
        'webhook_secret' => 'super-secret-webhook-hmac-value',
        'status' => GitProviderStatus::Active,
    ], $overrides));
}

it('migrates existing domain_deployments as manual with auto_deploy false', function () {
    $user = User::factory()->create(['username' => 'legacyuser']);
    $domain = makeTestDomain($user, 'legacy.example.com');

    DB::table('domain_deployment_configs')->insert([
        'domain_id' => $domain->id,
        'mode' => 'atomic',
        'preset' => 'laravel',
        'branch' => 'main',
        'fetch_driver' => 'shallow_git',
        'webhook_token' => Str::random(40),
        'web_subpath' => 'public',
        'php_version' => '83',
        'keep_releases' => 5,
        'health_check_enabled' => true,
        'health_check_path' => '/',
        'auto_rollback' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $config = DomainDeploymentConfig::where('domain_id', $domain->id)->firstOrFail();

    expect($config->source_mode)->toBe(SourceMode::Manual);
    expect($config->git_provider_id)->toBeNull();
    expect($config->remote_repo_id)->toBeNull();
    expect($config->remote_repo_full_name)->toBeNull();
    expect($config->auto_deploy)->toBeFalse();
});

it('encrypts credentials and webhook_secret at rest in database', function () {
    $user = User::factory()->create(['username' => 'encuser']);

    $provider = makeTestGitProvider($user, [
        'credentials' => [
            'pem' => 'PRIVATE_PEM_SECRET_MARKER',
            'client_id' => 'Iv1.client123',
            'client_secret' => 'CLIENT_SECRET_MARKER',
        ],
        'webhook_secret' => 'WEBHOOK_SECRET_MARKER',
    ]);

    $rawRow = DB::table('git_providers')->where('id', $provider->id)->first();

    expect($rawRow)->not->toBeNull();
    expect($rawRow->credentials)->not->toContain('PRIVATE_PEM_SECRET_MARKER');
    expect($rawRow->credentials)->not->toContain('CLIENT_SECRET_MARKER');
    expect($rawRow->webhook_secret)->not->toContain('WEBHOOK_SECRET_MARKER');

    $fresh = $provider->fresh();
    $credentials = $fresh->safeCredentials();

    expect($credentials)->toBeInstanceOf(ProviderCredentials::class);
    expect($credentials->pem)->toBe('PRIVATE_PEM_SECRET_MARKER');
    expect($credentials->clientId)->toBe('Iv1.client123');
    expect($credentials->clientSecret)->toBe('CLIENT_SECRET_MARKER');
    expect($fresh->safeWebhookSecret())->toBe('WEBHOOK_SECRET_MARKER');

    // Ensure hidden attributes are not leaked when serialized
    $serialized = $fresh->toArray();
    expect($serialized)->not->toHaveKey('credentials');
    expect($serialized)->not->toHaveKey('webhook_secret');
});

it('handles DecryptException cleanly without crashing and flags decryption_failed', function () {
    Log::spy();

    $user = User::factory()->create(['username' => 'decryptuser']);
    $provider = makeTestGitProvider($user);

    // Simulate APP_KEY rotation / corrupted ciphertext in database
    DB::table('git_providers')
        ->where('id', $provider->id)
        ->update([
            'credentials' => 'not-valid-encrypted-ciphertext',
            'webhook_secret' => 'not-valid-encrypted-webhook-secret',
        ]);

    $fresh = $provider->fresh();

    expect($fresh->safeCredentials())->toBeNull();
    expect($fresh->status)->toBe(GitProviderStatus::DecryptionFailed);
    expect($fresh->fresh()->status)->toBe(GitProviderStatus::DecryptionFailed);
    expect($fresh->safeWebhookSecret())->toBeNull();

    Log::shouldHaveReceived('error')
        ->with('Failed to decrypt GitProvider secret attribute.', Mockery::subset([
            'git_provider_id' => $provider->id,
            'attribute' => 'credentials',
        ]))
        ->once();
});

it('forbids User B from viewing, deleting, or binding User A git provider (403)', function () {
    $userA = User::factory()->create(['username' => 'owner_a', 'role' => 'user']);
    $userB = User::factory()->create(['username' => 'other_b', 'role' => 'user']);
    $admin = User::factory()->create(['username' => 'admin_c', 'role' => 'admin']);

    $provider = makeTestGitProvider($userA);

    expect(Gate::forUser($userA)->allows('view', $provider))->toBeTrue();
    expect(Gate::forUser($userA)->allows('update', $provider))->toBeTrue();
    expect(Gate::forUser($userA)->allows('delete', $provider))->toBeTrue();
    expect(Gate::forUser($userA)->allows('bind', $provider))->toBeTrue();

    foreach ([$userB, $admin] as $otherUser) {
        expect(Gate::forUser($otherUser)->denies('view', $provider))->toBeTrue();
        expect(Gate::forUser($otherUser)->denies('update', $provider))->toBeTrue();
        expect(Gate::forUser($otherUser)->denies('delete', $provider))->toBeTrue();
        expect(Gate::forUser($otherUser)->denies('bind', $provider))->toBeTrue();
    }

    // Verify per-user provider cap enforcement
    config()->set('litecontrol.max_git_providers_per_user', 1);
    expect(Gate::forUser($userA)->denies('create', GitProvider::class))->toBeTrue();
    expect(Gate::forUser($userB)->allows('create', GitProvider::class))->toBeTrue();
});

it('prevents deleting a git_provider referenced by a domain_deployment at DB level', function () {
    $user = User::factory()->create(['username' => 'fkuser']);
    $domain = makeTestDomain($user, 'bound.example.com');
    $provider = makeTestGitProvider($user);

    DomainDeploymentConfig::create([
        'domain_id' => $domain->id,
        'mode' => 'atomic',
        'source_mode' => SourceMode::Provider,
        'git_provider_id' => $provider->id,
        'remote_repo_id' => '123456789',
        'remote_repo_full_name' => 'acme/web-app',
        'auto_deploy' => true,
        'webhook_token' => Str::random(40),
    ]);

    expect(fn () => DB::table('git_providers')->where('id', $provider->id)->delete())
        ->toThrow(QueryException::class);

    expect(GitProvider::find($provider->id))->not->toBeNull();
});

it('detaches git_provider_id when domain ownership transfers to another user and records audit log', function () {
    Log::spy();

    $userA = User::factory()->create(['username' => 'transfer_a']);
    $userB = User::factory()->create(['username' => 'transfer_b']);
    $admin = User::factory()->create(['username' => 'transfer_admin', 'role' => 'admin']);

    $domain = makeTestDomain($userA, 'transfer.example.com');
    $provider = makeTestGitProvider($userA);

    $config = DomainDeploymentConfig::create([
        'domain_id' => $domain->id,
        'mode' => 'atomic',
        'source_mode' => SourceMode::Provider,
        'git_provider_id' => $provider->id,
        'remote_repo_id' => '987654321',
        'remote_repo_full_name' => 'acme/transfer-app',
        'auto_deploy' => true,
        'webhook_token' => Str::random(40),
    ]);

    app(TransferDomainOwnershipAction::class)->handle($domain, $userB, $admin, '127.0.0.1');

    $domain->refresh();
    $config->refresh();

    expect($domain->user_id)->toBe($userB->id);
    expect($config->git_provider_id)->toBeNull();
    expect($config->source_mode)->toBe(SourceMode::Manual);
    expect($config->auto_deploy)->toBeFalse();

    Log::shouldHaveReceived('warning')
        ->with('Detached git provider from domain deployment config after domain ownership transfer.', Mockery::subset([
            'domain_id' => $domain->id,
            'previous_user_id' => $userA->id,
            'new_user_id' => $userB->id,
            'previous_git_provider_id' => $provider->id,
        ]))
        ->once();

    $detachAudit = AuditLog::query()
        ->where('action', 'source_control.provider_detached_on_transfer')
        ->first();

    expect($detachAudit)->not->toBeNull();
    expect($detachAudit->user_id)->toBe($admin->id);
    expect($detachAudit->status)->toBe('warning');
    expect($detachAudit->details)->toMatchArray([
        'domain_id' => $domain->id,
        'previous_user_id' => $userA->id,
        'new_user_id' => $userB->id,
        'previous_git_provider_id' => $provider->id,
    ]);
});
