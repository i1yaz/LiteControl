<?php

declare(strict_types=1);

use App\Domains\Deployment\Actions\BuildDeploymentPayloadAction;
use App\Domains\Deployment\Models\DomainDeployment;
use App\Domains\Deployment\Models\DomainDeploymentConfig;
use App\Domains\Deployment\Models\DomainDeploymentHook;
use App\Domains\Identity\Models\User;
use App\Domains\WebServer\Models\Domain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('assembles the lc-cli payload json, writes deploy key, and groups active hooks', function () {
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
        'health_check_enabled' => true,
        'health_check_path' => '/',
        'auto_rollback' => true,
    ]);

    DomainDeploymentHook::create([
        'config_id' => $config->id,
        'name' => 'Composer Install',
        'stage' => 'install',
        'position' => 'run',
        'script' => '{{composer}} install --no-dev',
        'sort_order' => 10,
        'is_active' => true,
    ]);

    DomainDeploymentHook::create([
        'config_id' => $config->id,
        'name' => 'Migrate',
        'stage' => 'install',
        'position' => 'after',
        'script' => '{{php}} {{release}}/artisan migrate --force',
        'sort_order' => 20,
        'is_active' => true,
    ]);

    DomainDeploymentHook::create([
        'config_id' => $config->id,
        'name' => 'Disabled Hook',
        'stage' => 'activate',
        'position' => 'after',
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
            'deployment_id' => $deployment->id,
            'release_id' => '20261119_020500_abc123',
            'linux_user' => 'client1',
            'domain' => 'example.com',
            'web_subpath' => 'public',
            'php_binary' => '/usr/local/lsws/lsphp83/bin/php',
            'composer_cmd' => '/usr/local/lsws/lsphp83/bin/php /usr/local/bin/composer',
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

        expect($payload['hooks']['install'])->toBe(['{{composer}} install --no-dev']);
        expect($payload['hooks']['after_install'])->toBe(['{{php}} {{release}}/artisan migrate --force']);
        expect($payload['hooks']['after_activate'])->toBe([]);

        $expectedKeyPath = "{$tempHome}/client1/example.com/.deploy/keys/deploy_key";
        $expectedPayloadPath = "{$tempHome}/client1/example.com/.deploy/payloads/deploy_20261119_020500_abc123.json";

        expect(File::exists($expectedKeyPath))->toBeTrue();
        expect(File::exists($expectedPayloadPath))->toBeTrue();
        expect(json_decode(File::get($expectedPayloadPath), true))->toBe($payload);
    } finally {
        File::deleteDirectory($tempHome);
    }
});
