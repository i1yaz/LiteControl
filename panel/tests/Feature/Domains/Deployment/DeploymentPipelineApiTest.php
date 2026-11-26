<?php

declare(strict_types=1);

use App\Domains\Deployment\Enums\DeploymentHookSlot;
use App\Domains\Deployment\Models\DomainDeploymentConfig;
use App\Domains\Deployment\Models\DomainDeploymentHook;
use App\Domains\Identity\Models\User;
use App\Domains\WebServer\Models\Domain;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, RefreshDatabase::class);

/**
 * @return array{0: User, 1: Domain, 2: DomainDeploymentConfig}
 */
function makePipelineSiteFixture(string $username, string $domainName, string $role = 'user'): array
{
    $user = User::factory()->create([
        'username' => $username,
        'role' => $role,
    ]);

    $domain = Domain::create([
        'user_id' => $user->id,
        'domain' => $domainName,
        'document_root' => "/home/{$username}/{$domainName}/public_html",
        'php_version' => '8.3',
    ]);

    $config = DomainDeploymentConfig::create([
        'domain_id' => $domain->id,
        'mode' => 'atomic',
        'preset' => 'laravel',
        'webhook_token' => "token-{$username}-".uniqid(),
    ]);

    return [$user, $domain, $config];
}

it('creates, updates, and deletes custom hooks with after_purge default and sequential sort_order', function () {
    [$user, $domain, $config] = makePipelineSiteFixture('pipeowner', 'app.example.com');

    // Create without slot -> defaults to after_purge, sort_order 0, trims name, normalizes CRLF
    $createFirst = $this->actingAs($user)
        ->postJson(route('domains.deploy.hooks.store', $domain), [
            'name' => '  Notify Slack  ',
            'script' => "echo start\r\necho done",
        ]);

    $createFirst->assertCreated();
    $firstHookId = (int) $createFirst->json('hook.id');

    $firstHook = DomainDeploymentHook::findOrFail($firstHookId);
    expect($firstHook->name)->toBe('Notify Slack');
    expect($firstHook->slot)->toBe(DeploymentHookSlot::AfterPurge);
    expect($firstHook->script)->toBe("echo start\necho done");
    expect($firstHook->sort_order)->toBe(0);
    expect($firstHook->is_active)->toBeTrue();

    // Second hook in same default slot gets sort_order 1
    $createSecond = $this->actingAs($user)
        ->postJson(route('domains.deploy.hooks.store', $domain), [
            'name' => 'Ping Monitoring',
            'script' => 'curl -fsS https://status.example.com',
        ]);

    $createSecond->assertCreated();
    $secondHook = DomainDeploymentHook::findOrFail((int) $createSecond->json('hook.id'));
    expect($secondHook->slot)->toBe(DeploymentHookSlot::AfterPurge);
    expect($secondHook->sort_order)->toBe(1);

    // Update hook: change name, script CRLF, slot to after_install
    $updateResponse = $this->actingAs($user)
        ->putJson(route('domains.deploy.hooks.update', [$domain, $firstHook]), [
            'name' => '  Run Migrations  ',
            'slot' => 'after_install',
            'script' => "{{php}} {{release}}/artisan migrate --force\r\necho migrated",
            'is_active' => false,
        ]);

    $updateResponse->assertOk();
    $firstHook->refresh();
    expect($firstHook->name)->toBe('Run Migrations');
    expect($firstHook->slot)->toBe(DeploymentHookSlot::AfterInstall);
    expect($firstHook->script)->toBe("{{php}} {{release}}/artisan migrate --force\necho migrated");
    expect($firstHook->sort_order)->toBe(0);
    expect($firstHook->is_active)->toBeFalse();

    // Delete hook
    $this->actingAs($user)
        ->deleteJson(route('domains.deploy.hooks.destroy', [$domain, $firstHook]))
        ->assertOk()
        ->assertJson(['deleted' => true]);

    expect(DomainDeploymentHook::find($firstHook->id))->toBeNull();
    expect(DomainDeploymentHook::where('config_id', $config->id)->count())->toBe(1);
});

it('saves and clears Stage 2 install script with CRLF normalization and whitespace-to-null handling', function () {
    [$user, $domain, $config] = makePipelineSiteFixture('stage2user', 'stage2.example.com');

    // Save with CRLF
    $this->actingAs($user)
        ->putJson(route('domains.deploy.pipeline.install-script', $domain), [
            'install_script' => "{{composer}} install --no-dev\r\nnpm ci\r\nnpm run build",
        ])
        ->assertOk()
        ->assertJsonPath('install_script', "{{composer}} install --no-dev\nnpm ci\nnpm run build")
        ->assertJsonPath('pipeline.stages.1.script', "{{composer}} install --no-dev\nnpm ci\nnpm run build");

    expect($config->fresh()->install_script)
        ->toBe("{{composer}} install --no-dev\nnpm ci\nnpm run build");

    // Clear via whitespace-only string -> stored as null
    $this->actingAs($user)
        ->putJson(route('domains.deploy.pipeline.install-script', $domain), [
            'install_script' => "   \r\n\t  \n ",
        ])
        ->assertOk()
        ->assertJsonPath('install_script', null)
        ->assertJsonPath('pipeline.stages.1.script', null);

    expect($config->fresh()->install_script)->toBeNull();

    // Also works when passed via updateConfig
    $this->actingAs($user)
        ->put(route('domains.deploy.config', $domain), [
            'mode' => 'atomic',
            'preset' => 'laravel',
            'branch' => 'main',
            'fetch_driver' => 'shallow_git',
            'keep_releases' => 5,
            'health_check_enabled' => false,
            'health_check_path' => '/',
            'auto_rollback' => true,
            'install_script' => "composer install\r\nnpm ci",
        ])
        ->assertRedirect();

    expect($config->fresh()->install_script)->toBe("composer install\nnpm ci");
});

it('rejects invalid hook payloads including blank fields, invalid slots, and {{release}} in before_clone', function () {
    [$user, $domain, $config] = makePipelineSiteFixture('valuser', 'validate.example.com');

    // Blank / whitespace-only name and script
    $this->actingAs($user)
        ->postJson(route('domains.deploy.hooks.store', $domain), [
            'name' => '    ',
            'script' => "  \r\n\t ",
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'script']);

    // Name exceeding 120 chars
    $this->actingAs($user)
        ->postJson(route('domains.deploy.hooks.store', $domain), [
            'name' => str_repeat('a', 121),
            'script' => 'echo ok',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    // Invalid slot (legacy before_install)
    $this->actingAs($user)
        ->postJson(route('domains.deploy.hooks.store', $domain), [
            'name' => 'Legacy Slot',
            'slot' => 'before_install',
            'script' => 'echo ok',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['slot']);

    // {{release}} in before_clone on create
    $this->actingAs($user)
        ->postJson(route('domains.deploy.hooks.store', $domain), [
            'name' => 'Invalid Pre-Clone',
            'slot' => 'before_clone',
            'script' => 'cd {{release}} && ls -la',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['script']);

    // Create a valid before_clone hook, then attempt to edit its script to reference {{release}}
    $beforeCloneHook = DomainDeploymentHook::create([
        'config_id' => $config->id,
        'name' => 'Valid Pre-Clone',
        'slot' => DeploymentHookSlot::BeforeClone,
        'script' => 'echo "Preparing {{project}}"',
        'sort_order' => 0,
    ]);

    $this->actingAs($user)
        ->putJson(route('domains.deploy.hooks.update', [$domain, $beforeCloneHook]), [
            'name' => 'Valid Pre-Clone',
            'script' => 'touch {{release}}/marker',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['script']);

    // Create a valid after_install hook using {{release}}, then attempt to move it to before_clone via update
    $releaseHook = DomainDeploymentHook::create([
        'config_id' => $config->id,
        'name' => 'Artisan Migrate',
        'slot' => DeploymentHookSlot::AfterInstall,
        'script' => '{{php}} {{release}}/artisan migrate --force',
        'sort_order' => 0,
    ]);

    $this->actingAs($user)
        ->putJson(route('domains.deploy.hooks.update', [$domain, $releaseHook]), [
            'name' => 'Artisan Migrate',
            'slot' => 'before_clone',
            'script' => '{{php}} {{release}}/artisan migrate --force',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['script']);

    // Attempt to move the {{release}} hook into before_clone via reorder
    $this->actingAs($user)
        ->putJson(route('domains.deploy.hooks.reorder', $domain), [
            'hooks' => [
                ['id' => $beforeCloneHook->id, 'slot' => 'after_clone', 'sort_order' => 0],
                ['id' => $releaseHook->id, 'slot' => 'before_clone', 'sort_order' => 0],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['hooks.1.slot']);

    // Original slots remain untouched after failed reorder
    expect($beforeCloneHook->fresh()->slot)->toBe(DeploymentHookSlot::BeforeClone);
    expect($releaseHook->fresh()->slot)->toBe(DeploymentHookSlot::AfterInstall);
});

it('enforces reorder security and integrity against duplicates, cross-site IDs, partial payloads, and unauthorized users', function () {
    [$userA, $domainA, $configA] = makePipelineSiteFixture('sitea', 'site-a.example.com');
    [$userB, $domainB, $configB] = makePipelineSiteFixture('siteb', 'site-b.example.com');

    $hookA1 = DomainDeploymentHook::create([
        'config_id' => $configA->id,
        'name' => 'A1',
        'slot' => DeploymentHookSlot::AfterClone,
        'script' => 'echo a1',
        'sort_order' => 0,
    ]);
    $hookA2 = DomainDeploymentHook::create([
        'config_id' => $configA->id,
        'name' => 'A2',
        'slot' => DeploymentHookSlot::AfterInstall,
        'script' => 'echo a2',
        'sort_order' => 0,
    ]);
    $hookB1 = DomainDeploymentHook::create([
        'config_id' => $configB->id,
        'name' => 'B1',
        'slot' => DeploymentHookSlot::AfterPurge,
        'script' => 'echo b1',
        'sort_order' => 0,
    ]);

    // Reject duplicate IDs
    $this->actingAs($userA)
        ->putJson(route('domains.deploy.hooks.reorder', $domainA), [
            'hooks' => [
                ['id' => $hookA1->id, 'slot' => 'after_clone', 'sort_order' => 0],
                ['id' => $hookA1->id, 'slot' => 'after_install', 'sort_order' => 0],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['hooks.1.id']);

    // Reject cross-site hook ID
    $this->actingAs($userA)
        ->putJson(route('domains.deploy.hooks.reorder', $domainA), [
            'hooks' => [
                ['id' => $hookA1->id, 'slot' => 'after_clone', 'sort_order' => 0],
                ['id' => $hookA2->id, 'slot' => 'after_install', 'sort_order' => 0],
                ['id' => $hookB1->id, 'slot' => 'after_purge', 'sort_order' => 0],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['hooks.2.id']);

    // Reject partial payload (missing hookA2)
    $this->actingAs($userA)
        ->putJson(route('domains.deploy.hooks.reorder', $domainA), [
            'hooks' => [
                ['id' => $hookA1->id, 'slot' => 'after_purge', 'sort_order' => 0],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['hooks']);

    // Reject unknown hook ID
    $this->actingAs($userA)
        ->putJson(route('domains.deploy.hooks.reorder', $domainA), [
            'hooks' => [
                ['id' => $hookA1->id, 'slot' => 'after_clone', 'sort_order' => 0],
                ['id' => 999999, 'slot' => 'after_install', 'sort_order' => 0],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['hooks.1.id']);

    // Reject another non-admin user from accessing domainA's pipeline
    $this->actingAs($userB)
        ->putJson(route('domains.deploy.hooks.reorder', $domainA), [
            'hooks' => [
                ['id' => $hookA1->id, 'slot' => 'after_clone', 'sort_order' => 0],
                ['id' => $hookA2->id, 'slot' => 'after_install', 'sort_order' => 0],
            ],
        ])
        ->assertForbidden();

    // Ensure site B's hook was never touched
    expect($hookB1->fresh()->slot)->toBe(DeploymentHookSlot::AfterPurge);
});

it('reorders hooks across all 5 slots and within the same slot in a single bulk UPDATE without N+1 queries', function () {
    [$user, $domain, $config] = makePipelineSiteFixture('bulkuser', 'bulk.example.com');

    $hooks = [];
    for ($i = 1; $i <= 6; $i++) {
        $hooks[$i] = DomainDeploymentHook::create([
            'config_id' => $config->id,
            'name' => "Hook {$i}",
            'slot' => DeploymentHookSlot::AfterPurge,
            'script' => "echo hook-{$i}",
            'sort_order' => $i - 1,
        ]);
    }

    $updateQueries = [];
    DB::listen(static function (QueryExecuted $query) use (&$updateQueries): void {
        if (preg_match('/^\s*update\s+["`]?domain_deployment_hooks["`]?/i', $query->sql) === 1) {
            $updateQueries[] = $query->sql;
        }
    });

    $response = $this->actingAs($user)
        ->putJson(route('domains.deploy.hooks.reorder', $domain), [
            'hooks' => [
                ['id' => $hooks[1]->id, 'slot' => 'before_clone', 'sort_order' => 0],
                ['id' => $hooks[2]->id, 'slot' => 'after_clone', 'sort_order' => 0],
                ['id' => $hooks[3]->id, 'slot' => 'after_install', 'sort_order' => 1],
                ['id' => $hooks[4]->id, 'slot' => 'after_install', 'sort_order' => 0],
                ['id' => $hooks[5]->id, 'slot' => 'after_activate', 'sort_order' => 0],
                ['id' => $hooks[6]->id, 'slot' => 'after_purge', 'sort_order' => 0],
            ],
        ]);

    $response->assertOk();

    // Exactly 1 bulk UPDATE query for all 6 hooks (no N+1 per-row save loops)
    expect($updateQueries)->toHaveCount(1);

    // Verify persisted state
    expect($hooks[1]->fresh()->slot)->toBe(DeploymentHookSlot::BeforeClone);
    expect($hooks[2]->fresh()->slot)->toBe(DeploymentHookSlot::AfterClone);
    expect($hooks[4]->fresh()->slot)->toBe(DeploymentHookSlot::AfterInstall);
    expect($hooks[4]->fresh()->sort_order)->toBe(0);
    expect($hooks[3]->fresh()->slot)->toBe(DeploymentHookSlot::AfterInstall);
    expect($hooks[3]->fresh()->sort_order)->toBe(1);
    expect($hooks[5]->fresh()->slot)->toBe(DeploymentHookSlot::AfterActivate);
    expect($hooks[6]->fresh()->slot)->toBe(DeploymentHookSlot::AfterPurge);
});

it('returns deterministic pipeline read response with 4 locked stages, placeholders, and ordered hooks', function () {
    [$user, $domain, $config] = makePipelineSiteFixture('readuser', 'read.example.com');
    $config->update(['install_script' => '{{composer}} install --no-dev']);

    // Insert hooks intentionally out of slot and sort_order sequence
    $hAfterPurge = DomainDeploymentHook::create([
        'config_id' => $config->id,
        'name' => 'Z Cleanup Notify',
        'slot' => DeploymentHookSlot::AfterPurge,
        'script' => 'echo purge',
        'sort_order' => 0,
    ]);
    $hAfterInstallSecond = DomainDeploymentHook::create([
        'config_id' => $config->id,
        'name' => 'Cache Config',
        'slot' => DeploymentHookSlot::AfterInstall,
        'script' => '{{php}} {{release}}/artisan config:cache',
        'sort_order' => 1,
    ]);
    $hAfterInstallTieA = DomainDeploymentHook::create([
        'config_id' => $config->id,
        'name' => 'Migrate A',
        'slot' => DeploymentHookSlot::AfterInstall,
        'script' => '{{php}} {{release}}/artisan migrate --force',
        'sort_order' => 0,
    ]);
    $hAfterInstallTieB = DomainDeploymentHook::create([
        'config_id' => $config->id,
        'name' => 'Migrate B',
        'slot' => DeploymentHookSlot::AfterInstall,
        'script' => 'echo tie-b',
        'sort_order' => 0,
    ]);
    $hBeforeClone = DomainDeploymentHook::create([
        'config_id' => $config->id,
        'name' => 'Pre-Clone Check',
        'slot' => DeploymentHookSlot::BeforeClone,
        'script' => 'echo pre',
        'sort_order' => 0,
    ]);

    $response = $this->actingAs($user)
        ->getJson(route('domains.deploy.pipeline.show', $domain));

    $response->assertOk();
    $data = $response->json();

    expect(array_column($data['stages'], 'key'))->toBe(['clone', 'install', 'activate', 'purge']);
    expect($data['stages'][1]['script'])->toBe('{{composer}} install --no-dev');
    expect($data['install_script'])->toBe('{{composer}} install --no-dev');

    expect(array_column($data['slots'], 'key'))->toBe([
        'before_clone',
        'after_clone',
        'after_install',
        'after_activate',
        'after_purge',
    ]);

    expect(array_column($data['placeholders'], 'token'))->toBe([
        '{{release}}',
        '{{project}}',
        '{{shared}}',
        '{{php}}',
        '{{composer}}',
        '{{branch}}',
        '{{sha}}',
    ]);

    expect(array_column($data['hooks'], 'id'))->toBe([
        $hBeforeClone->id,
        $hAfterInstallTieA->id,
        $hAfterInstallTieB->id,
        $hAfterInstallSecond->id,
        $hAfterPurge->id,
    ]);
});
