<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Security\Actions\BuildImunifyIntegrationDataAction;
use App\Domains\WebServer\Models\Domain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('builds valid CloudLinux Imunify integration JSON for all 7 contract sections', function () {
    $user = User::factory()->create([
        'username' => 'alice',
        'email' => 'alice@example.com',
        'role' => 'user',
        'system_uid' => 1005,
    ]);

    Domain::create([
        'user_id' => $user->id,
        'domain' => 'alice.example.com',
        'type' => 'primary',
        'document_root' => '/home/alice/domains/alice.example.com/public_html',
        'php_version' => '8.3',
        'status' => 'active',
    ]);

    $action = app(BuildImunifyIntegrationDataAction::class);

    $panelInfo = $action->handle('panel_info');
    expect($panelInfo['metadata']['result'])->toBe('ok')
        ->and($panelInfo['data']['name'])->toBe('LiteControl');

    $admins = $action->handle('admins');
    expect($admins['metadata']['result'])->toBe('ok')
        ->and($admins['data'])->toBeArray()
        ->and($admins['data'][0]['name'])->toBe('root')
        ->and($admins['data'][0]['is_main'])->toBeTrue();

    $users = $action->handle('users');
    expect($users['metadata']['result'])->toBe('ok')
        ->and(collect($users['data'])->firstWhere('username', 'alice'))->toMatchArray([
            'id' => 1005,
            'username' => 'alice',
            'owner' => 'root',
            'domain' => 'alice.example.com',
            'email' => 'alice@example.com',
        ]);

    $domains = (array) $action->handle('domains')['data'];
    expect($domains)->toHaveKey('alice.example.com')
        ->and($domains['alice.example.com'])->toMatchArray([
            'document_root' => '/home/alice/domains/alice.example.com/public_html',
            'is_main' => true,
            'owner' => 'alice',
        ]);

    $packages = $action->handle('packages');
    expect($packages['metadata']['result'])->toBe('ok')
        ->and($packages['data'])->toBeArray()->not->toBeEmpty();

    $resellers = $action->handle('resellers');
    expect($resellers['metadata']['result'])->toBe('ok')
        ->and($resellers['data'])->toBeArray();

    $dbInfo = $action->handle('db_info');
    expect($dbInfo['metadata']['result'])->toBe('ok')
        ->and($dbInfo['data'])->toHaveKeys(['host', 'port']);
});

it('executes artisan imunify:integration-data command and fails cleanly on invalid section', function () {
    $this->artisan('imunify:integration-data', ['section' => 'panel_info'])
        ->assertSuccessful();

    $this->artisan('imunify:integration-data', ['section' => 'unknown_section'])
        ->assertFailed();
});
