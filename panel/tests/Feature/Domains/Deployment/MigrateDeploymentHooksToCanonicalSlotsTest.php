<?php

declare(strict_types=1);

use App\Domains\Deployment\Enums\DeploymentHookSlot;
use App\Domains\Deployment\Models\DomainDeploymentConfig;
use App\Domains\Deployment\Models\DomainDeploymentHook;
use App\Domains\Identity\Models\User;
use App\Domains\WebServer\Models\Domain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class, RefreshDatabase::class);

function loadCanonicalSlotsMigration(): object
{
    return require database_path('migrations/2026_11_26_000002_migrate_domain_deployment_hooks_to_canonical_slots.php');
}

function createDeploymentConfigFor(string $domainName, string $username): DomainDeploymentConfig
{
    $user = User::factory()->create(['username' => $username]);
    $domain = Domain::create([
        'user_id' => $user->id,
        'domain' => $domainName,
        'document_root' => "/home/{$username}/{$domainName}/public_html",
        'php_version' => '8.3',
    ]);

    return DomainDeploymentConfig::create([
        'domain_id' => $domain->id,
        'mode' => 'atomic',
        'preset' => 'laravel',
        'webhook_token' => "token-{$username}",
    ]);
}

it('adds nullable install_script column to domain_deployment_configs and composite index on hooks', function () {
    expect(Schema::hasColumn('domain_deployment_configs', 'install_script'))->toBeTrue();
    expect(Schema::hasColumn('domain_deployment_hooks', 'slot'))->toBeTrue();

    $config = createDeploymentConfigFor('install-script.example.com', 'installuser');
    expect($config->fresh()->install_script)->toBeNull();

    $config->update(['install_script' => '{{composer}} install --no-dev']);
    expect($config->fresh()->install_script)->toBe('{{composer}} install --no-dev');

    $hook = DomainDeploymentHook::create([
        'config_id' => $config->id,
        'name' => 'Default Slot Hook',
        'script' => 'echo ok',
    ]);

    expect($hook->fresh()->slot)->toBe(DeploymentHookSlot::AfterPurge);
    expect($hook->fresh()->sort_order)->toBe(0);

    $indexes = Schema::getIndexes('domain_deployment_hooks');
    $indexNames = array_column($indexes, 'name');
    expect($indexNames)->toContain('dd_hooks_config_slot_sort_idx');
});

it('runs cleanly on an empty domain_deployment_hooks table', function () {
    $migration = loadCanonicalSlotsMigration();

    $migration->down();
    expect(Schema::hasColumn('domain_deployment_hooks', 'slot'))->toBeFalse();
    expect(DB::table('domain_deployment_hooks')->count())->toBe(0);

    $migration->up();
    expect(Schema::hasColumn('domain_deployment_hooks', 'slot'))->toBeTrue();
    expect(DB::table('domain_deployment_hooks')->count())->toBe(0);
});

it('maps all 8 legacy slot positions deterministically with Stage N After preceding Stage N+1 Before', function () {
    $config = createDeploymentConfigFor('pipeline.example.com', 'pipeuser');
    $migration = loadCanonicalSlotsMigration();

    $migration->down();

    $now = now();
    // Intentionally give Stage N+1 Before lower legacy sort_order numbers than Stage N After
    // to prove merge priority always keeps Stage N After strictly before Stage N+1 Before.
    DB::table('domain_deployment_hooks')->insert([
        // Stage 1 Before -> before_clone
        ['config_id' => $config->id, 'name' => 'S1 Before B', 'stage' => 'clone', 'position' => 'before', 'script' => 'echo s1b2', 'sort_order' => 20, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ['config_id' => $config->id, 'name' => 'S1 Before A', 'stage' => 'clone', 'position' => 'before', 'script' => 'echo s1b1', 'sort_order' => 10, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],

        // Merged into after_clone: Stage 1 After, then Stage 2 Before, then legacy Stage 2 Run
        ['config_id' => $config->id, 'name' => 'S2 Before', 'stage' => 'install', 'position' => 'before', 'script' => 'echo s2b', 'sort_order' => 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ['config_id' => $config->id, 'name' => 'S1 After', 'stage' => 'clone', 'position' => 'after', 'script' => 'echo s1a', 'sort_order' => 50, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ['config_id' => $config->id, 'name' => 'S2 Run Legacy', 'stage' => 'install', 'position' => 'run', 'script' => '{{composer}} install', 'sort_order' => 0, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],

        // Merged into after_install: Stage 2 After, then Stage 3 Before
        ['config_id' => $config->id, 'name' => 'S3 Before', 'stage' => 'activate', 'position' => 'before', 'script' => 'echo s3b', 'sort_order' => 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ['config_id' => $config->id, 'name' => 'S2 After', 'stage' => 'install', 'position' => 'after', 'script' => 'echo s2a', 'sort_order' => 40, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],

        // Merged into after_activate: Stage 3 After, then Stage 4 Before (cleanup & purge aliases)
        ['config_id' => $config->id, 'name' => 'S4 Before Cleanup', 'stage' => 'cleanup', 'position' => 'before', 'script' => 'echo s4b1', 'sort_order' => 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ['config_id' => $config->id, 'name' => 'S4 Before Purge', 'stage' => 'purge', 'position' => 'before', 'script' => 'echo s4b2', 'sort_order' => 2, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ['config_id' => $config->id, 'name' => 'S3 After', 'stage' => 'activate', 'position' => 'after', 'script' => 'echo s3a', 'sort_order' => 99, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],

        // Stage 4 After -> after_purge
        ['config_id' => $config->id, 'name' => 'S4 After Cleanup', 'stage' => 'cleanup', 'position' => 'after', 'script' => 'echo s4a1', 'sort_order' => 10, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ['config_id' => $config->id, 'name' => 'S4 After Purge', 'stage' => 'purge', 'position' => 'after', 'script' => 'echo s4a2', 'sort_order' => 20, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
    ]);

    $migration->up();

    $bySlot = fn (string $slot): array => DB::table('domain_deployment_hooks')
        ->where('config_id', $config->id)
        ->where('slot', $slot)
        ->orderBy('sort_order')
        ->get(['name', 'sort_order'])
        ->map(static fn (object $r): array => [$r->name, (int) $r->sort_order])
        ->all();

    expect($bySlot('before_clone'))->toBe([
        ['S1 Before A', 0],
        ['S1 Before B', 1],
    ]);

    expect($bySlot('after_clone'))->toBe([
        ['S1 After', 0],
        ['S2 Before', 1],
        ['S2 Run Legacy', 2],
    ]);

    expect($bySlot('after_install'))->toBe([
        ['S2 After', 0],
        ['S3 Before', 1],
    ]);

    expect($bySlot('after_activate'))->toBe([
        ['S3 After', 0],
        ['S4 Before Cleanup', 1],
        ['S4 Before Purge', 2],
    ]);

    expect($bySlot('after_purge'))->toBe([
        ['S4 After Cleanup', 0],
        ['S4 After Purge', 1],
    ]);
});

it('re-indexes sort_order cleanly per site and slot and handles duplicates and unknown values safely', function () {
    $configA = createDeploymentConfigFor('site-a.example.com', 'sitea');
    $configB = createDeploymentConfigFor('site-b.example.com', 'siteb');
    $migration = loadCanonicalSlotsMigration();

    $migration->down();

    $now = now();
    DB::table('domain_deployment_hooks')->insert([
        // Site A: duplicate legacy sort_order values in after_install
        ['config_id' => $configA->id, 'name' => 'A First Dup', 'stage' => 'install', 'position' => 'after', 'script' => 'echo a1', 'sort_order' => 5, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ['config_id' => $configA->id, 'name' => 'A Second Dup', 'stage' => 'build', 'position' => 'after', 'script' => 'echo a2', 'sort_order' => 5, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        // Site A: unknown legacy stage falls back safely to after_purge
        ['config_id' => $configA->id, 'name' => 'A Unknown Stage', 'stage' => 'legacy_unknown', 'position' => 'after', 'script' => 'echo unknown', 'sort_order' => 0, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ['config_id' => $configA->id, 'name' => 'A Explicit S4 After', 'stage' => 'cleanup', 'position' => 'after', 'script' => 'echo s4a', 'sort_order' => 100, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],

        // Site B: independent 0-indexed sequence in after_install
        ['config_id' => $configB->id, 'name' => 'B Only Hook', 'stage' => 'install', 'position' => 'after', 'script' => 'echo b1', 'sort_order' => 77, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
    ]);

    $migration->up();

    $siteAAfterInstall = DB::table('domain_deployment_hooks')
        ->where('config_id', $configA->id)
        ->where('slot', 'after_install')
        ->orderBy('sort_order')
        ->get(['name', 'sort_order'])
        ->map(static fn (object $r): array => [$r->name, (int) $r->sort_order])
        ->all();

    expect($siteAAfterInstall)->toBe([
        ['A First Dup', 0],
        ['A Second Dup', 1],
    ]);

    $siteAAfterPurge = DB::table('domain_deployment_hooks')
        ->where('config_id', $configA->id)
        ->where('slot', 'after_purge')
        ->orderBy('sort_order')
        ->get(['name', 'sort_order'])
        ->map(static fn (object $r): array => [$r->name, (int) $r->sort_order])
        ->all();

    expect($siteAAfterPurge)->toBe([
        ['A Explicit S4 After', 0],
        ['A Unknown Stage', 1],
    ]);

    $siteBAfterInstall = DB::table('domain_deployment_hooks')
        ->where('config_id', $configB->id)
        ->where('slot', 'after_install')
        ->orderBy('sort_order')
        ->get(['name', 'sort_order'])
        ->map(static fn (object $r): array => [$r->name, (int) $r->sort_order])
        ->all();

    expect($siteBAfterInstall)->toBe([
        ['B Only Hook', 0],
    ]);
});
