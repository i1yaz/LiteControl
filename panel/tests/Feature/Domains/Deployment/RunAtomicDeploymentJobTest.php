<?php

declare(strict_types=1);

use App\Domains\Deployment\Actions\BuildDeploymentPayloadAction;
use App\Domains\Deployment\Jobs\RunAtomicDeploymentJob;
use App\Domains\Deployment\Models\DomainDeployment;
use App\Domains\Deployment\Models\DomainDeploymentConfig;
use App\Domains\Identity\Models\User;
use App\Domains\WebServer\Models\Domain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeDeploymentFixture(): array
{
    $user = User::factory()->create(['username' => 'client1']);
    $domain = Domain::create([
        'user_id' => $user->id,
        'domain' => 'example.com',
        'document_root' => '/home/client1/example.com/public_html',
        'php_version' => '8.3',
    ]);

    $config = DomainDeploymentConfig::create([
        'domain_id' => $domain->id,
        'mode' => 'atomic',
        'preset' => 'laravel',
        'repo_url' => 'git@github.com:org/repo.git',
        'branch' => 'main',
        'fetch_driver' => 'shallow_git',
        'webhook_token' => 'token-123',
    ]);

    $previous = DomainDeployment::create([
        'domain_id' => $domain->id,
        'release_id' => '20261119_010000_prev',
        'trigger_type' => 'manual',
        'status' => 'active',
    ]);

    $current = DomainDeployment::create([
        'domain_id' => $domain->id,
        'release_id' => '20261119_020000_curr',
        'trigger_type' => 'manual',
        'status' => 'queued',
    ]);

    return [$domain, $config, $previous, $current];
}

it('marks deployment active and supersedes previous active release on exit code 0', function () {
    [$domain, $config, $previous, $current] = makeDeploymentFixture();

    $builder = Mockery::mock(BuildDeploymentPayloadAction::class);
    $builder->shouldReceive('execute')
        ->once()
        ->andReturn(['domain_root' => '/home/client1/example.com']);

    Process::fake([
        '*' => Process::result(
            output: json_encode([
                'commit_sha' => 'a1b2c3d',
                'commit_message' => 'feat: deploy',
                'commit_author' => 'Jane Doe',
            ]),
            exitCode: 0,
        ),
    ]);

    (new RunAtomicDeploymentJob($config, $current))->handle($builder);

    expect($previous->fresh()->status)->toBe('superseded');
    expect($current->fresh())
        ->status->toBe('active')
        ->commit_sha->toBe('a1b2c3d')
        ->commit_message->toBe('feat: deploy')
        ->commit_author->toBe('Jane Doe')
        ->finished_at->not->toBeNull();
});

it('marks deployment rolled_back on exit code 2', function () {
    [$domain, $config, $previous, $current] = makeDeploymentFixture();

    $builder = Mockery::mock(BuildDeploymentPayloadAction::class);
    $builder->shouldReceive('execute')
        ->once()
        ->andReturn(['domain_root' => '/home/client1/example.com']);

    Process::fake([
        '*' => Process::result(output: '', errorOutput: 'Health check 500', exitCode: 2),
    ]);

    (new RunAtomicDeploymentJob($config, $current))->handle($builder);

    expect($previous->fresh()->status)->toBe('active');
    expect($current->fresh())
        ->status->toBe('rolled_back')
        ->error_summary->toBe('HTTP health check failed (5xx); auto-rolled back to previous release.')
        ->finished_at->not->toBeNull();
});

it('marks deployment failed on other non-zero exit codes', function () {
    [$domain, $config, $previous, $current] = makeDeploymentFixture();

    $builder = Mockery::mock(BuildDeploymentPayloadAction::class);
    $builder->shouldReceive('execute')
        ->once()
        ->andReturn(['domain_root' => '/home/client1/example.com']);

    Process::fake([
        '*' => Process::result(output: '', errorOutput: 'composer install failed', exitCode: 1),
    ]);

    (new RunAtomicDeploymentJob($config, $current))->handle($builder);

    expect($previous->fresh()->status)->toBe('active');
    expect($current->fresh())
        ->status->toBe('failed')
        ->error_summary->toBe('composer install failed')
        ->log_path->not->toBeNull()
        ->finished_at->not->toBeNull();
    expect(file_get_contents($current->fresh()->log_path))->toContain('composer install failed');
});

it('marks deployment failed and records log when an exception is thrown during setup', function () {
    [$domain, $config, $previous, $current] = makeDeploymentFixture();

    $builder = Mockery::mock(BuildDeploymentPayloadAction::class);
    $builder->shouldReceive('execute')
        ->once()
        ->andThrow(new RuntimeException('Permission denied writing payload'));

    (new RunAtomicDeploymentJob($config, $current))->handle($builder);

    $fresh = $current->fresh();
    expect($previous->fresh()->status)->toBe('active');
    expect($fresh)
        ->status->toBe('failed')
        ->error_summary->toBe('Permission denied writing payload')
        ->log_path->not->toBeNull()
        ->finished_at->not->toBeNull();
    expect(file_get_contents($fresh->log_path))->toContain('Permission denied writing payload');
});

it('purges logs for deployments older than keep_releases after a successful deploy', function () {
    [$domain, $config, $previous, $current] = makeDeploymentFixture();
    $config->update(['keep_releases' => 2]);

    $oldLogPath = storage_path('logs/deployments/test_old_release.log');
    \Illuminate\Support\Facades\File::ensureDirectoryExists(dirname($oldLogPath));
    \Illuminate\Support\Facades\File::put($oldLogPath, 'old deployment log');

    $oldest = DomainDeployment::create([
        'domain_id' => $domain->id,
        'release_id' => '20261118_010000_oldest',
        'trigger_type' => 'manual',
        'status' => 'superseded',
        'log_path' => $oldLogPath,
    ]);
    // Ensure $oldest has an ID older than $previous and $current
    DomainDeployment::where('id', $oldest->id)->update(['id' => $previous->id - 1]);
    $oldest = DomainDeployment::find($previous->id - 1);

    $builder = Mockery::mock(BuildDeploymentPayloadAction::class);
    $builder->shouldReceive('execute')
        ->once()
        ->andReturn(['domain_root' => '/home/client1/example.com']);

    Process::fake([
        '*' => Process::result(
            output: json_encode([
                'commit_sha' => 'f00ba77',
                'commit_message' => 'feat: prune',
                'commit_author' => 'Jane Doe',
            ]),
            exitCode: 0,
        ),
    ]);

    (new RunAtomicDeploymentJob($config, $current))->handle($builder);

    expect(\Illuminate\Support\Facades\File::exists($oldLogPath))->toBeFalse();
    expect($oldest->fresh()->log_path)->toBeNull();
    expect($current->fresh()->log_path)->not->toBeNull();
});

it('truncates long stderr into a concise error_summary within column limits', function () {
    [$domain, $config, $previous, $current] = makeDeploymentFixture();

    $builder = Mockery::mock(BuildDeploymentPayloadAction::class);
    $builder->shouldReceive('execute')
        ->once()
        ->andReturn(['domain_root' => '/home/client1/example.com']);

    $longStderr = str_repeat("Installing dependencies...\n", 100).'error: hook install[0] failed: exit status 100';

    Process::fake([
        '*' => Process::result(output: '', errorOutput: $longStderr, exitCode: 1),
    ]);

    (new RunAtomicDeploymentJob($config, $current))->handle($builder);

    $fresh = $current->fresh();
    expect($fresh->status)->toBe('failed');
    expect($fresh->error_summary)->toBe('error: hook install[0] failed: exit status 100');
    expect(strlen((string) $fresh->error_summary))->toBeLessThanOrEqual(500);
});
