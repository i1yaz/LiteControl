<?php

use App\Domains\Identity\Models\User;
use App\Domains\WebServer\Actions\UpdateSiteTuningAction;
use App\Domains\WebServer\Contracts\VhostDriverInterface;
use App\Domains\WebServer\Models\Domain;
use App\Domains\WebServer\Models\SiteTuning;
use App\Domains\WebServer\ValueObjects\SiteTuningConfig;
use App\Exceptions\System\LcCliException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('updates and applies per-site tuning successfully', function () {
    $user = User::factory()->create(['username' => 'tenant1']);
    $domain = Domain::create([
        'user_id' => $user->id,
        'domain' => 'example.com',
        'type' => 'primary',
        'document_root' => '/home/tenant1/public_html',
        'php_version' => '83',
        'status' => 'active',
    ]);

    $mock = Mockery::mock(VhostDriverInterface::class);
    $mock->shouldReceive('applyTuning')
        ->once()
        ->withArgs(function (string $d, string $u, string $php, SiteTuningConfig $c) {
            return $d === 'example.com'
                && $u === 'tenant1'
                && $php === '83'
                && $c->maxConns === 25
                && $c->phpMemoryLimitMb === 512;
        })
        ->andReturn([]);

    app()->instance(VhostDriverInterface::class, $mock);

    $config = SiteTuningConfig::fromArray([
        'max_conns' => 25,
        'mem_soft_limit_mb' => 512,
        'mem_hard_limit_mb' => 1024,
        'php_memory_limit_mb' => 512,
    ]);

    $tuning = app(UpdateSiteTuningAction::class)->handle($domain, $config, $user, '127.0.0.1');

    expect($tuning)->toBeInstanceOf(SiteTuning::class)
        ->and($tuning->max_conns)->toBe(25)
        ->and($tuning->php_memory_limit_mb)->toBe(512)
        ->and($tuning->sync_status)->toBe('applied')
        ->and($tuning->applied_at)->not->toBeNull();

    $this->assertDatabaseHas('site_tunings', [
        'domain_id' => $domain->id,
        'max_conns' => 25,
        'sync_status' => 'applied',
    ]);
});

test('does not persist tuning when OS apply fails', function () {
    $user = User::factory()->create(['username' => 'tenant2']);
    $domain = Domain::create([
        'user_id' => $user->id,
        'domain' => 'fail.com',
        'type' => 'primary',
        'document_root' => '/home/tenant2/public_html',
        'php_version' => '83',
        'status' => 'active',
    ]);

    $mock = Mockery::mock(VhostDriverInterface::class);
    $mock->shouldReceive('applyTuning')
        ->once()
        ->andThrow(new LcCliException('OLS reload failed', 'vhost', ['apply-tuning']));

    app()->instance(VhostDriverInterface::class, $mock);

    $config = SiteTuningConfig::defaults();

    expect(fn () => app(UpdateSiteTuningAction::class)->handle($domain, $config, $user))
        ->toThrow(LcCliException::class, 'OLS reload failed');

    $this->assertDatabaseMissing('site_tunings', [
        'domain_id' => $domain->id,
    ]);
});
