<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Shared\Contracts\ModuleRegistryQueryInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('shares modules.mail_core, powerdns, and ftp as false when inactive', function () {
    $user = User::factory()->create(['username' => 'mailadmin', 'role' => 'admin']);

    $mock = $this->mock(ModuleRegistryQueryInterface::class);
    $mock->shouldReceive('isActive')->with('mail_core')->andReturn(false);
    $mock->shouldReceive('isActive')->with('powerdns')->andReturn(false);
    $mock->shouldReceive('isActive')->with('ftp')->andReturn(false);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('modules.mail_core', false)
            ->where('modules.powerdns', false)
            ->where('modules.ftp', false)
        );
});

it('shares modules.mail_core, powerdns, and ftp as true when active', function () {
    $user = User::factory()->create(['username' => 'mailadmin', 'role' => 'admin']);

    $mock = $this->mock(ModuleRegistryQueryInterface::class);
    $mock->shouldReceive('isActive')->with('mail_core')->andReturn(true);
    $mock->shouldReceive('isActive')->with('powerdns')->andReturn(true);
    $mock->shouldReceive('isActive')->with('ftp')->andReturn(true);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('modules.mail_core', true)
            ->where('modules.powerdns', true)
            ->where('modules.ftp', true)
        );
});
