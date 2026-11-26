<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Shared\Contracts\ModuleRegistryQueryInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('shares active_modules.mail_core, dns, and ftp as false when inactive', function () {
    $user = User::factory()->create(['username' => 'mailadmin', 'role' => 'admin']);

    $mock = $this->mock(ModuleRegistryQueryInterface::class);
    $mock->shouldReceive('isActive')->with('mail_core')->andReturn(false);
    $mock->shouldReceive('isActive')->with('dns')->andReturn(false);
    $mock->shouldReceive('isActive')->with('ftp')->andReturn(false);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('active_modules.mail_core', false)
            ->where('active_modules.dns', false)
            ->where('active_modules.ftp', false)
        );
});

it('shares active_modules.mail_core, dns, and ftp as true when active', function () {
    $user = User::factory()->create(['username' => 'mailadmin', 'role' => 'admin']);

    $mock = $this->mock(ModuleRegistryQueryInterface::class);
    $mock->shouldReceive('isActive')->with('mail_core')->andReturn(true);
    $mock->shouldReceive('isActive')->with('dns')->andReturn(true);
    $mock->shouldReceive('isActive')->with('ftp')->andReturn(true);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('active_modules.mail_core', true)
            ->where('active_modules.dns', true)
            ->where('active_modules.ftp', true)
        );
});

it('does not clobber shared active_modules on the /modules manager page', function () {
    $user = User::factory()->create(['username' => 'mailadmin2', 'role' => 'admin']);

    $mock = $this->mock(ModuleRegistryQueryInterface::class);
    $mock->shouldReceive('isActive')->with('mail_core')->andReturn(true);
    $mock->shouldReceive('isActive')->with('dns')->andReturn(false);
    $mock->shouldReceive('isActive')->with('ftp')->andReturn(false);

    $this->actingAs($user)
        ->get('/modules')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ServerOps/Modules')
            ->has('modules')
            ->where('active_modules.mail_core', true)
            ->where('active_modules.dns', false)
            ->where('active_modules.ftp', false)
        );
});
