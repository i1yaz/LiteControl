<?php

declare(strict_types=1);

use App\Domains\Deployment\Actions\BuildDeploymentPayloadAction;
use App\Domains\Deployment\Actions\DispatchDomainDeploymentAction;
use App\Domains\Deployment\Contracts\DeploymentDriverInterface;
use App\Domains\Deployment\Jobs\RunAtomicDeploymentJob;
use App\Domains\Deployment\Models\DomainDeployment;
use App\Domains\Deployment\Models\DomainDeploymentConfig;
use App\Domains\Identity\Models\User;
use App\Domains\SourceControl\Contracts\GitProviderDriverInterface;
use App\Domains\SourceControl\Enums\GitProviderStatus;
use App\Domains\SourceControl\Enums\SourceMode;
use App\Domains\SourceControl\Models\GitProvider;
use App\Domains\WebServer\Models\Domain;
use App\Exceptions\System\LcCliException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function deployTestRsaPem(): string
{
    static $pem = null;

    if ($pem === null) {
        $baseConfig = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $resource = @openssl_pkey_new($baseConfig);
        $exported = '';

        if ($resource !== false && @openssl_pkey_export($resource, $exported, null, $baseConfig) && $exported !== '') {
            $pem = $exported;

            return $pem;
        }

        $tempCnf = tempnam(sys_get_temp_dir(), 'lc_openssl_');
        if ($tempCnf === false) {
            throw new RuntimeException('Unable to create temporary openssl.cnf for deployment tests.');
        }

        file_put_contents($tempCnf, "[req]\ndistinguished_name=dn\n[dn]\n");

        try {
            $usedConfig = $baseConfig + ['config' => $tempCnf];
            $resource = openssl_pkey_new($usedConfig);
            if ($resource === false || ! openssl_pkey_export($resource, $exported, null, $usedConfig) || $exported === '') {
                throw new RuntimeException('Unable to generate RSA keypair for deployment tests.');
            }
            $pem = $exported;
        } finally {
            @unlink($tempCnf);
        }
    }

    return $pem;
}

function makeProviderDeploymentFixture(array $configOverrides = [], array $deploymentOverrides = []): array
{
    $user = User::factory()->create(['username' => 'tenant1']);

    $domain = Domain::create([
        'user_id' => $user->id,
        'domain' => 'app.example.com',
        'document_root' => '/home/tenant1/app.example.com/public_html',
        'php_version' => '8.3',
    ]);

    $pem = deployTestRsaPem();

    $provider = GitProvider::create([
        'uuid' => (string) Str::uuid(),
        'user_id' => $user->id,
        'provider' => 'github_app',
        'label' => 'Primary GitHub App',
        'base_url' => 'https://api.github.com',
        'external_app_id' => '98765',
        'external_app_slug' => 'lc-test-app',
        'external_account_login' => 'acme-org',
        'external_account_type' => 'Organization',
        'installation_id' => 555111,
        'repository_selection' => 'selected',
        'credentials' => [
            'pem' => $pem,
            'client_id' => 'Iv1.testclient',
            'client_secret' => 'test_client_secret_value',
        ],
        'webhook_secret' => 'whsec_test_secret',
        'status' => GitProviderStatus::Active,
    ]);

    $config = DomainDeploymentConfig::create(array_merge([
        'domain_id' => $domain->id,
        'mode' => 'atomic',
        'source_mode' => SourceMode::Provider,
        'git_provider_id' => $provider->id,
        'remote_repo_id' => '777888',
        'remote_repo_full_name' => 'acme-org/web-app',
        'auto_deploy' => true,
        'preset' => 'laravel',
        'repo_url' => 'https://github.com/acme-org/web-app.git',
        'branch' => 'main',
        'fetch_driver' => 'provider_tarball',
        'webhook_token' => 'tok-provider-1',
    ], $configOverrides));

    $previous = DomainDeployment::create([
        'domain_id' => $domain->id,
        'release_id' => '20261124_010000_prev',
        'trigger_type' => 'manual',
        'status' => 'active',
    ]);

    $current = DomainDeployment::create(array_merge([
        'domain_id' => $domain->id,
        'release_id' => '20261124_020000_curr',
        'trigger_type' => 'manual',
        'status' => 'queued',
        'commit_sha' => null,
    ], $deploymentOverrides));

    return [$user, $domain, $provider, $config, $previous, $current];
}

it('does not serialize git tokens onto queued ExecuteDeploymentJob', function () {
    [$user, $domain, $provider, $config, $previous, $current] = makeProviderDeploymentFixture();

    $job = new RunAtomicDeploymentJob($config, $current);
    $serialized = serialize($job);

    expect($serialized)
        ->not->toContain('BEGIN PRIVATE KEY')
        ->not->toContain('BEGIN RSA PRIVATE KEY')
        ->not->toContain('test_client_secret_value')
        ->not->toContain('whsec_test_secret')
        ->not->toContain('ghs_');
});

it('leaves manual mode lc-cli invocation identical to legacy golden snapshot', function () {
    [$user, $domain, $provider, $config, $previous, $current] = makeProviderDeploymentFixture([
        'source_mode' => SourceMode::Manual,
        'git_provider_id' => null,
        'remote_repo_id' => null,
        'remote_repo_full_name' => null,
        'fetch_driver' => 'shallow_git',
    ]);

    $builder = Mockery::mock(BuildDeploymentPayloadAction::class);
    $builder->shouldReceive('execute')
        ->once()
        ->andReturn([
            'domain_root' => '/home/tenant1/app.example.com',
            'spec_path' => '/home/tenant1/app.example.com/.deploy/payloads/deploy_20261124_020000_curr.json',
        ]);

    $capturedCommand = null;
    $capturedInput = 'NOT_SET';

    Process::fake([
        '*' => Process::result(
            output: json_encode([
                'commit_sha' => 'abc1234',
                'commit_message' => 'chore: legacy manual deploy',
                'commit_author' => 'Dev User',
            ]),
            exitCode: 0,
        ),
    ]);

    (new RunAtomicDeploymentJob($config, $current))->handle($builder);

    $cliPath = (string) env('LC_CLI_PATH', '/usr/local/litecontrol/bin/lc-cli');
    Process::assertRan(function (PendingProcess $process) use ($cliPath, &$capturedCommand, &$capturedInput): bool {
        $capturedCommand = $process->command;
        $capturedInput = $process->input;

        return $process->command === [
            'sudo',
            $cliPath,
            'deploy',
            'run',
            '--spec=/home/tenant1/app.example.com/.deploy/payloads/deploy_20261124_020000_curr.json',
        ];
    });

    expect($capturedInput)->toBeNull();
    expect($capturedCommand)->not->toContain('--fetch-payload-stdin');
    expect($current->fresh()->status)->toBe('active');
    expect($previous->fresh()->status)->toBe('superseded');
});

it('resolves commit SHA before invoking tarball fetch by repository ID', function () {
    [$user, $domain, $provider, $config, $previous, $current] = makeProviderDeploymentFixture([
        'fetch_driver' => 'provider_tarball',
        'branch' => 'release/v2',
    ], [
        'commit_sha' => null,
    ]);

    Http::fake([
        'https://api.github.com/app/installations/555111/access_tokens' => Http::response([
            'token' => 'ghs_ephemeral_scoped_token_999',
        ], 201),
        'https://api.github.com/repos/acme-org/web-app/commits/release/v2' => Http::response([
            'sha' => '11223344556677889900aabbccddeeff11223344',
        ], 200),
    ]);

    $builder = Mockery::mock(BuildDeploymentPayloadAction::class);
    $builder->shouldReceive('execute')
        ->once()
        ->andReturn([
            'domain_root' => '/home/tenant1/app.example.com',
            'spec_path' => '/home/tenant1/app.example.com/.deploy/payloads/deploy_20261124_020000_curr.json',
        ]);

    $capturedStdin = null;
    $capturedCommand = null;

    Process::fake([
        '*' => Process::result(
            output: json_encode([
                'status' => 'active',
                'commit_sha' => '11223344556677889900aabbccddeeff11223344',
            ]),
            exitCode: 0,
        ),
    ]);

    (new RunAtomicDeploymentJob($config, $current))->handle($builder);

    Process::assertRan(function (PendingProcess $process) use (&$capturedStdin, &$capturedCommand): bool {
        $capturedCommand = $process->command;
        $capturedStdin = $process->input;

        return is_array($process->command) && in_array('--fetch-payload-stdin', $process->command, true);
    });

    // Ensure token is NEVER passed in CLI arguments
    expect(implode(' ', $capturedCommand ?? []))->not->toContain('ghs_ephemeral_scoped_token_999');

    $stdinPayload = json_decode((string) $capturedStdin, true, 512, JSON_THROW_ON_ERROR);
    expect($stdinPayload)->toMatchArray([
        'schema_version' => 1,
        'mode' => 'tarball',
        'commit_sha' => '11223344556677889900aabbccddeeff11223344',
        'branch' => 'release/v2',
        'tarball_url' => 'https://api.github.com/repositories/777888/tarball/11223344556677889900aabbccddeeff11223344',
        'auth_header' => 'Bearer ghs_ephemeral_scoped_token_999',
    ]);

    $fresh = $current->fresh();
    expect($fresh->status)->toBe('active');
    expect($fresh->commit_sha)->toBe('11223344556677889900aabbccddeeff11223344');
    expect(file_get_contents((string) $fresh->log_path))->not->toContain('ghs_ephemeral_scoped_token_999');
});

it('pipes shallow_clone credentials over stdin when provider mode uses shallow_git', function () {
    [$user, $domain, $provider, $config, $previous, $current] = makeProviderDeploymentFixture([
        'fetch_driver' => 'shallow_git',
        'branch' => 'main',
    ], [
        'commit_sha' => 'fedcba9876543210fedcba9876543210fedcba98',
    ]);

    $driver = Mockery::mock(GitProviderDriverInterface::class);
    $driver->shouldReceive('mintInstallationToken')
        ->once()
        ->with(Mockery::on(fn (GitProvider $p): bool => $p->id === $provider->id), '777888')
        ->andReturn('ghs_shallow_clone_secret_777');
    $driver->shouldNotReceive('resolveHeadCommitSha');
    app()->instance(GitProviderDriverInterface::class, $driver);

    $builder = Mockery::mock(BuildDeploymentPayloadAction::class);
    $builder->shouldReceive('execute')
        ->once()
        ->andReturn([
            'domain_root' => '/home/tenant1/app.example.com',
            'spec_path' => '/home/tenant1/app.example.com/.deploy/payloads/deploy_20261124_020000_curr.json',
        ]);

    $capturedStdin = null;
    Process::fake([
        '*' => Process::result(output: json_encode(['status' => 'active']), exitCode: 0),
    ]);

    (new RunAtomicDeploymentJob($config, $current))->handle($builder);

    Process::assertRan(function (PendingProcess $process) use (&$capturedStdin): bool {
        $capturedStdin = $process->input;

        return in_array('--fetch-payload-stdin', $process->command, true);
    });

    $stdinPayload = json_decode((string) $capturedStdin, true, 512, JSON_THROW_ON_ERROR);
    expect($stdinPayload)->toMatchArray([
        'schema_version' => 1,
        'mode' => 'shallow_clone',
        'commit_sha' => 'fedcba9876543210fedcba9876543210fedcba98',
        'branch' => 'main',
        'clone_url' => 'https://github.com/acme-org/web-app.git',
        'https_username' => 'x-access-token',
        'https_token' => 'ghs_shallow_clone_secret_777',
    ]);
});

it('aborts deploy job if domain owner no longer matches git provider owner', function () {
    [$user, $domain, $provider, $config, $previous, $current] = makeProviderDeploymentFixture();

    $otherUser = User::factory()->create(['username' => 'tenant2']);
    $domain->update(['user_id' => $otherUser->id]);

    $driver = Mockery::mock(GitProviderDriverInterface::class);
    $driver->shouldNotReceive('mintInstallationToken');
    app()->instance(GitProviderDriverInterface::class, $driver);

    $builder = Mockery::mock(BuildDeploymentPayloadAction::class);
    $builder->shouldNotReceive('execute');

    Process::fake();

    (new RunAtomicDeploymentJob($config->fresh(), $current))->handle($builder);

    Process::assertNothingRan();

    expect($previous->fresh()->status)->toBe('active');
    expect($current->fresh())
        ->status->toBe('failed')
        ->error_summary->toContain('domain owner does not match');
});

it('rolls back deployment status cleanly on LcCliException without leaking tokens', function () {
    Queue::fake();

    [$user, $domain, $provider, $config, $previous, $current] = makeProviderDeploymentFixture([
        'fetch_driver' => 'provider_tarball',
    ], [
        'commit_sha' => 'aabbccddeeff00112233445566778899aabbccdd',
    ]);

    // Simulate active per-domain deploy lock + coalesced pending push waiting in cache
    Cache::lock(DispatchDomainDeploymentAction::lockKey($domain->id), 660)->get();
    Cache::put(DispatchDomainDeploymentAction::pendingKey($domain->id), [
        'commit_sha' => '99887766554433221100ffeeddccbbaa99887766',
        'trigger' => 'webhook',
    ], 1800);

    $secretToken = 'ghs_must_never_leak_in_logs_or_db_12345';

    $gitDriver = Mockery::mock(GitProviderDriverInterface::class);
    $gitDriver->shouldReceive('mintInstallationToken')
        ->once()
        ->andReturn($secretToken);
    app()->instance(GitProviderDriverInterface::class, $gitDriver);

    $builder = Mockery::mock(BuildDeploymentPayloadAction::class);
    $builder->shouldReceive('execute')
        ->once()
        ->andReturn([
            'domain_root' => '/home/tenant1/app.example.com',
            'spec_path' => '/home/tenant1/app.example.com/.deploy/payloads/deploy_20261124_020000_curr.json',
        ]);

    $deployDriver = Mockery::mock(DeploymentDriverInterface::class);
    $deployDriver->shouldReceive('runAtomicDeploy')
        ->once()
        ->andThrow(new LcCliException(
            message: "tarball download failed with header Bearer {$secretToken}",
            command: 'deploy run',
        ));
    app()->instance(DeploymentDriverInterface::class, $deployDriver);

    (new RunAtomicDeploymentJob($config, $current))->handle($builder);

    // Previous release remains active; failed deployment is cleanly marked failed with redacted summary
    expect($previous->fresh()->status)->toBe('active');
    $freshCurrent = $current->fresh();
    expect($freshCurrent->status)->toBe('failed');
    expect($freshCurrent->error_summary)
        ->toContain('[REDACTED]')
        ->not->toContain($secretToken);
    expect(file_get_contents((string) $freshCurrent->log_path))
        ->toContain('[REDACTED]')
        ->not->toContain($secretToken);

    // Lock was released and coalesced pending deployment was dispatched
    expect(Cache::get(DispatchDomainDeploymentAction::pendingKey($domain->id)))->toBeNull();
    Queue::assertPushed(RunAtomicDeploymentJob::class, function (RunAtomicDeploymentJob $queued): bool {
        return $queued->deployment->commit_sha === '99887766554433221100ffeeddccbbaa99887766';
    });
});
