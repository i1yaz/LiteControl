<?php

declare(strict_types=1);

use App\Domains\ServerOps\Contracts\ModuleDriverInterface;
use App\Domains\ServerOps\Infrastructure\ModuleRegistryQuery;
use App\Domains\Shared\Contracts\ModuleRegistryQueryInterface;
use Tests\TestCase;

uses(TestCase::class);

it('binds ModuleRegistryQueryInterface to ModuleRegistryQuery', function () {
    expect(app(ModuleRegistryQueryInterface::class))
        ->toBeInstanceOf(ModuleRegistryQuery::class);
});

it('reports active state and driver from ModuleDriverInterface', function () {
    $driver = Mockery::mock(ModuleDriverInterface::class);
    $driver->shouldReceive('getModuleStatus')
        ->with('mail_core')
        ->andReturn(['installed' => true, 'status' => 'active', 'driver' => 'postfix_dovecot']);
    $driver->shouldReceive('getModuleStatus')
        ->with('mail_filter')
        ->andReturn(['installed' => false, 'status' => 'not_installed', 'driver' => 'rspamd']);
    $driver->shouldReceive('getModuleStatus')
        ->with('dns')
        ->andReturn(['installed' => true, 'status' => 'active']);

    $query = new ModuleRegistryQuery($driver);

    expect($query->isActive('mail_core'))->toBeTrue()
        ->and($query->activeDriver('mail_core'))->toBe('postfix_dovecot')
        ->and($query->isActive('mail_filter'))->toBeFalse()
        ->and($query->activeDriver('mail_filter'))->toBeNull()
        ->and($query->isActive('dns'))->toBeTrue()
        ->and($query->activeDriver('dns'))->toBeNull();
});
