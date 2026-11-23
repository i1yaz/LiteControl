<?php

declare(strict_types=1);

use App\Domains\Deployment\Actions\GenerateDeployKeyAction;
use App\Domains\Deployment\Models\DomainDeploymentConfig;
use App\Domains\Identity\Models\User;
use App\Domains\WebServer\Models\Domain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('generates an ed25519 deploy key pair for a domain', function () {
    $user = User::factory()->create(['username' => 'client1']);
    $domain = Domain::create([
        'user_id' => $user->id,
        'domain' => 'example.com',
        'document_root' => '/home/client1/example.com/public_html',
        'php_version' => '8.3',
    ]);

    $action = app(GenerateDeployKeyAction::class);
    $config = $action->execute($domain);

    expect($config)->toBeInstanceOf(DomainDeploymentConfig::class)
        ->and($config->deploy_key_public)->toStartWith('ssh-ed25519 ')
        ->and($config->deploy_key_private_encrypted)->toContain('BEGIN OPENSSH PRIVATE KEY');
});
