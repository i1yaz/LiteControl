<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Shared\Contracts\ModuleRegistryQueryInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('shares modules.mail_core as false when mail_core is inactive', function () {
    $user = User::factory()->create(['username' => 'mailadmin', 'role' => 'admin']);

    $this->mock(ModuleRegistryQueryInterface::class)
        ->shouldReceive('isActive')
        ->with('mail_core')
        ->andReturn(false);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('modules.mail_core', false)
        );
});

it('shares modules.mail_core as true when mail_core is active', function () {
    $user = User::factory()->create(['username' => 'mailadmin', 'role' => 'admin']);

    $this->mock(ModuleRegistryQueryInterface::class)
        ->shouldReceive('isActive')
        ->with('mail_core')
        ->andReturn(true);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('modules.mail_core', true)
        );
});
