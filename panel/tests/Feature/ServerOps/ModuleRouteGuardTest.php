<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Shared\Contracts\ModuleRegistryQueryInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('redirects admins to modules index when dns is not installed', function (string $uri) {
    $admin = User::factory()->create(['username' => 'dnsadmin', 'role' => 'admin']);

    $mock = $this->mock(ModuleRegistryQueryInterface::class);
    $mock->shouldReceive('isActive')->with('dns')->andReturn(false);

    $this->actingAs($admin)
        ->get($uri)
        ->assertRedirect(route('modules.index'))
        ->assertSessionHas('warning');
})->with([
    '/dns/zones',
    '/dns/templates',
    '/dns/settings',
]);

it('redirects admins to modules index when ftp is not installed', function () {
    $admin = User::factory()->create(['username' => 'ftpadmin', 'role' => 'admin']);

    $mock = $this->mock(ModuleRegistryQueryInterface::class);
    $mock->shouldReceive('isActive')->with('ftp')->andReturn(false);

    $this->actingAs($admin)
        ->get('/ftp')
        ->assertRedirect(route('modules.index'))
        ->assertSessionHas('warning');
});

it('redirects non-admin users to dashboard when ftp or mail_core is not installed', function (string $uri, string $module) {
    $user = User::factory()->create(['username' => 'regularuser', 'role' => 'user']);

    $mock = $this->mock(ModuleRegistryQueryInterface::class);
    $mock->shouldReceive('isActive')->with($module)->andReturn(false);

    $this->actingAs($user)
        ->get($uri)
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('warning');
})->with([
    ['/ftp', 'ftp'],
    ['/email/domains', 'mail_core'],
    ['/email/accounts', 'mail_core'],
    ['/email/forwarders', 'mail_core'],
    ['/email/dkim', 'mail_core'],
    ['/email/spam', 'mail_core'],
]);

it('redirects admins to modules index when mail_core is not installed', function (string $uri) {
    $admin = User::factory()->create(['username' => 'mailadmin2', 'role' => 'admin']);

    $mock = $this->mock(ModuleRegistryQueryInterface::class);
    $mock->shouldReceive('isActive')->with('mail_core')->andReturn(false);

    $this->actingAs($admin)
        ->get($uri)
        ->assertRedirect(route('modules.index'))
        ->assertSessionHas('warning');
})->with([
    '/email/domains',
    '/email/accounts',
    '/email/forwarders',
    '/email/dkim',
    '/email/spam',
]);

it('returns 403 json for json requests when required module is not installed', function () {
    $admin = User::factory()->create(['username' => 'jsonadmin', 'role' => 'admin']);

    $mock = $this->mock(ModuleRegistryQueryInterface::class);
    $mock->shouldReceive('isActive')->with('dns')->andReturn(false);

    $this->actingAs($admin)
        ->getJson('/dns/zones')
        ->assertForbidden()
        ->assertJson([
            'module' => 'dns',
        ]);
});

it('allows access to ftp, dns templates, and email domains when modules are installed', function () {
    $admin = User::factory()->create(['username' => 'fulladmin', 'role' => 'admin']);

    $mock = $this->mock(ModuleRegistryQueryInterface::class);
    $mock->shouldReceive('isActive')->andReturn(true);
    $mock->shouldReceive('activeDriver')->andReturn(null);

    $this->actingAs($admin)->get('/ftp')->assertOk();
    $this->actingAs($admin)->get('/dns/templates')->assertOk();
    $this->actingAs($admin)->get('/email/domains')->assertOk();
});
