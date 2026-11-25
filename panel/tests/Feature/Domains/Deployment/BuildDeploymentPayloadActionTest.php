<?php

declare(strict_types=1);

use App\Domains\Deployment\Actions\BuildDeploymentPayloadAction;
use App\Domains\Deployment\Enums\DeploymentHookSlot;
use App\Domains\Deployment\Models\DomainDeployment;
use App\Domains\Deployment\Models\DomainDeploymentConfig;
use App\Domains\Deployment\Models\DomainDeploymentHook;
use App\Domains\Identity\Models\User;
use App\Domains\WebServer\Models\Domain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('assembles the 5-slot lc-cli payload json with install_script, site_id, hook metadata, and deterministic ordering', function () {
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
        'webhook_token' => 'test-webhook-token',
        'deploy_key_private_encrypted' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntestkey\n-----END OPENSSH PRIVATE KEY-----",
        'web_subpath' => 'public',
        'php_version' => '83',
        'keep_releases' => 5,
        'shared_dirs' => ['storage'],
        'shared_files' => ['.env'],
        'install_script' => "{{composer}} install --no-dev\r\n{{php}} {{release}}/artisan package:discover",
        'health_check_enabled' => true,
        'health_check_path' => '/',
        'auto_rollback' => true,
    ]);

    $secondMigrate = DomainDeploymentHook::create([
        'config_id' => $config->id,
        'name' => 'Optimize',
        'slot' => DeploymentHookSlot::AfterInstall,
        'script' => '{{php}} {{release}}/artisan optimize',
        'sort_order' => 20,
        'is_active' => true,
    ]);

    $firstMigrate = DomainDeploymentHook::create([
        'config_id' => $config->id,
        'name' => 'Migrate',
        'slot' => DeploymentHookSlot::AfterInstall,
        'script' => "{{php}} {{release}}/artisan migrate --force\r\n",
        'sort_order' => 10,
        'is_active' => true,
    ]);

    $beforeClone = DomainDeploymentHook::create([
        'config_id' => $config->id,
        'name' => 'Pre-flight',
        'slot' => DeploymentHookSlot::BeforeClone,
        'script' => 'echo {{project}}',
        'sort_order' => 1,
        'is_active' => true,
    ]);

    DomainDeploymentHook::create([
        'config_id' => $config->id,
        'name' => 'Disabled Hook',
        'slot' => DeploymentHookSlot::AfterActivate,
        'script' => 'echo should-not-run',
        'sort_order' => 30,
        'is_active' => false,
    ]);

    $deployment = DomainDeployment::create([
        'domain_id' => $domain->id,
        'release_id' => '20261119_020500_abc123',
        'trigger_type' => 'manual',
        'status' => 'queued',
    ]);

    $tempHome = sys_get_temp_dir().DIRECTORY_SEPARATOR.'lc_deploy_test_'.uniqid();

    try {
        $action = new BuildDeploymentPayloadAction;
        $payload = $action->execute($config, $deployment, $tempHome);

        expect($payload)->toMatchArray([
            'site_id' => $domain->id,
            'deployment_id' => $deployment->id,
            'release_id' => '20261119_020500_abc123',
            'linux_user' => 'client1',
            'domain' => 'example.com',
            'web_subpath' => 'public',
            'php_binary' => '/usr/local/lsws/lsphp83/bin/php',
            'composer_cmd' => '/usr/local/lsws/lsphp83/bin/php /usr/local/bin/composer',
            'install_script' => "{{composer}} install --no-dev\n{{php}} {{release}}/artisan package:discover",
            'hook_timeout_seconds' => 300,
            'shared_dirs' => ['storage'],
            'shared_files' => ['.env'],
            'keep_releases' => 5,
            'health_check' => [
                'enabled' => true,
                'path' => '/',
                'timeout_seconds' => 5,
                'retries' => 2,
                'auto_rollback' => true,
            ],
        ]);

        expect(array_keys($payload['hooks']))->toBe(DeploymentHookSlot::values());
        expect($payload['hooks']['before_clone'])->toBe([
            [
                'id' => $beforeClone->id,
                'name' => 'Pre-flight',
                'script' => 'echo {{project}}',
            ],
        ]);
        expect($payload['hooks']['after_install'])->toBe([
            [
                'id' => $firstMigrate->id,
                'name' => 'Migrate',
                'script' => "{{php}} {{release}}/artisan migrate --force\n",
            ],
            [
                'id' => $secondMigrate->id,
                'name' => 'Optimize',
                'script' => '{{php}} {{release}}/artisan optimize',
            ],
        ]);
        expect($payload['hooks']['after_clone'])->toBe([]);
        expect($payload['hooks']['after_activate'])->toBe([]);
        expect($payload['hooks']['after_purge'])->toBe([]);

        $expectedKeyPath = "{$tempHome}/client1/example.com/.deploy/keys/deploy_key";
        $expectedPayloadPath = "{$tempHome}/client1/example.com/.deploy/payloads/deploy_20261119_020500_abc123.json";

        expect(File::exists($expectedKeyPath))->toBeTrue();
        expect(File::exists($expectedPayloadPath))->toBeTrue();
        expect(json_decode(File::get($expectedPayloadPath), true))->toBe($payload);
    } finally {
        File::deleteDirectory($tempHome);
    }
});

it('normalizes whitespace-only install_script to null in payload', function () {
    $user = User::factory()->create(['username' => 'client3']);
    $domain = Domain::create([
        'user_id' => $user->id,
        'domain' => 'skip.example.com',
        'document_root' => '/home/client3/skip.example.com/public_html',
        'php_version' => '8.3',
    ]);

    $config = DomainDeploymentConfig::create([
        'domain_id' => $domain->id,
        'mode' => 'atomic',
        'preset' => 'laravel',
        'repo_url' => 'git@github.com:org/repo.git',
        'branch' => 'main',
        'fetch_driver' => 'shallow_git',
        'webhook_token' => 'test-webhook-token-3',
        'install_script' => "   \r\n\t  ",
    ]);

    $deployment = DomainDeployment::create([
        'domain_id' => $domain->id,
        'release_id' => '20261126_010000_skip',
        'trigger_type' => 'manual',
        'status' => 'queued',
    ]);

    $tempHome = sys_get_temp_dir().DIRECTORY_SEPARATOR.'lc_deploy_skip_'.uniqid();

    try {
        $payload = (new BuildDeploymentPayloadAction)->execute($config, $deployment, $tempHome);
        expect($payload['install_script'])->toBeNull();
    } finally {
        File::deleteDirectory($tempHome);
    }
});

it('resolves github tarball_url when fetch_driver is provider_tarball', function () {
    $user = User::factory()->create(['username' => 'client2']);
    $domain = Domain::create([
        'user_id' => $user->id,
        'domain' => 'tarball.example.com',
        'document_root' => '/home/client2/tarball.example.com/public_html',
        'php_version' => '8.3',
    ]);

    $config = DomainDeploymentConfig::create([
        'domain_id' => $domain->id,
        'mode' => 'atomic',
        'preset' => 'laravel',
        'repo_provider' => 'github',
        'repo_url' => 'git@github.com:org/repo.git',
        'branch' => 'main',
        'fetch_driver' => 'provider_tarball',
        'webhook_token' => 'test-webhook-token-2',
    ]);

    $deployment = DomainDeployment::create([
        'domain_id' => $domain->id,
        'release_id' => '20261123_221500',
        'trigger_type' => 'manual',
        'status' => 'queued',
    ]);

    $tempHome = sys_get_temp_dir().DIRECTORY_SEPARATOR.'lc_deploy_tarball_'.uniqid();

    try {
        $payload = (new BuildDeploymentPayloadAction)->execute($config, $deployment, $tempHome);

        expect($payload['repo']['driver'])->toBe('provider_tarball');
        expect($payload['repo']['tarball_url'])->toBe('https://api.github.com/repos/org/repo/tarball/main');
    } finally {
        File::deleteDirectory($tempHome);
    }
});
