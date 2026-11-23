<?php

use App\Domains\ServerOps\Contracts\ServerDriverInterface;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Cache::forget('panel.server_ip');
});

it('returns SERVER_ADDR directly when it is not a loopback address', function () {
    $driver = Mockery::mock(ServerDriverInterface::class);
    $driver->shouldNotReceive('getStats');
    app()->instance(ServerDriverInterface::class, $driver);

    $request = Request::create('/dashboard', 'GET', [], [], [], [
        'SERVER_ADDR' => '213.163.192.85',
    ]);
    $request->setLaravelSession(app('session.store'));

    $shared = app(HandleInertiaRequests::class)->share($request);

    expect(($shared['panel']['server_ip'])())->toBe('213.163.192.85');
});

it('resolves and caches the real server IP when behind the local reverse proxy', function () {
    $driver = Mockery::mock(ServerDriverInterface::class);
    $driver->shouldReceive('getStats')
        ->once()
        ->andReturn(['server_ip' => '213.163.192.85']);
    app()->instance(ServerDriverInterface::class, $driver);

    $request = Request::create('/dashboard', 'GET', [], [], [], [
        'SERVER_ADDR' => '127.0.0.1',
    ]);
    $request->setLaravelSession(app('session.store'));

    $middleware = app(HandleInertiaRequests::class);

    expect(($middleware->share($request)['panel']['server_ip'])())->toBe('213.163.192.85');
    // Second call should hit cache rather than calling getStats() again
    expect(($middleware->share($request)['panel']['server_ip'])())->toBe('213.163.192.85');
});
