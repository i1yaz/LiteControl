<?php

declare(strict_types=1);

use App\Domains\Deployment\Actions\RollbackDeploymentAction;
use App\Domains\Deployment\Models\DomainDeployment;
use App\Domains\Deployment\Models\DomainDeploymentConfig;
use App\Domains\Identity\Models\User;
use App\Domains\WebServer\Models\Domain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('rolls back to target release via lc-cli and updates deployment history', function () {
    $user = User::factory()->create(['username' => 'client1']);
    $domain = Domain::create([
        'user_id' => $user->id,
        'domain' => 'example.com',
        'document_root' => '/home/client1/example.com/public_html',
        'php_version' => '8.3',
    ]);

    DomainDeploymentConfig::create([
        'domain_id' => $domain->id,
        'mode' => 'atomic',
        'preset' => 'laravel',
        'php_version' => '83',
        'webhook_token' => 'token-123',
    ]);

    $target = DomainDeployment::create([
        'domain_id' => $domain->id,
        'release_id' => '20261119_010000_old',
        'commit_sha' => '111aaaa',
        'trigger_type' => 'manual',
        'status' => 'superseded',
    ]);

    $current = DomainDeployment::create([
        'domain_id' => $domain->id,
        'release_id' => '20261119_020000_bad',
        'commit_sha' => '222bbbb',
        'trigger_type' => 'manual',
        'status' => 'active',
    ]);

    Process::fake([
        '*' => Process::result(output: 'Rolled back', exitCode: 0),
    ]);

    $audit = (new RollbackDeploymentAction)->execute($domain, $target);

    Process::assertRan(function ($process) {
        return $process->command === [
            'sudo',
            '/usr/local/bin/lc-cli',
            'deploy',
            'rollback',
            '--user=client1',
            '--domain=example.com',
            '--release=20261119_010000_old',
            '--php=/usr/local/lsws/lsphp83/bin/php',
        ];
    });

    expect($current->fresh()->status)->toBe('superseded');
    expect($target->fresh()->status)->toBe('active');
    expect($audit->trigger_type)->toBe('rollback');
    expect($audit->release_id)->toBe('20261119_010000_old');
});
