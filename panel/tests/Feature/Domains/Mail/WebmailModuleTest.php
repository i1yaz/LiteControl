<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Mail\Contracts\WebmailDriverInterface;
use App\Domains\Mail\Infrastructure\CliSnappyMailAdapter;
use App\Domains\Mail\Jobs\InstallWebmailJob;
use App\Domains\ServerOps\Actions\InstallModuleAction;
use App\Domains\ServerOps\Actions\RemoveModuleAction;
use App\Domains\ServerOps\Contracts\ModuleDriverInterface;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use App\Domains\Shared\ValueObjects\DomainName;
use App\Exceptions\System\LcCliException;
use App\Support\Cli\LcCliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('binds WebmailDriverInterface to CliSnappyMailAdapter', function () {
    $driver = app(WebmailDriverInterface::class);

    expect($driver)->toBeInstanceOf(CliSnappyMailAdapter::class)
        ->and($driver->key())->toBe('snappymail');
});

it('invokes lc-mail webmail-install, webmail-uninstall, and webmail-status via CliSnappyMailAdapter', function () {
    $cli = Mockery::mock(LcCliService::class);
    $cli->shouldReceive('runMail')
        ->once()
        ->with('webmail-install', ['--driver=snappymail', '--hostname=mail.example.com'])
        ->andReturn(['status' => 'ok', 'data' => ['status' => 'installed', 'driver' => 'snappymail']]);

    $cli->shouldReceive('runMail')
        ->once()
        ->with('webmail-status', ['--driver=snappymail'])
        ->andReturn(['status' => 'ok', 'data' => ['running' => true]]);

    $cli->shouldReceive('runMail')
        ->once()
        ->with('webmail-uninstall', ['--driver=snappymail'])
        ->andReturn(['status' => 'ok', 'data' => ['status' => 'uninstalled']]);

    $adapter = new CliSnappyMailAdapter($cli);

    expect($adapter->install('mail.example.com'))->toMatchArray(['status' => 'installed'])
        ->and($adapter->isRunning())->toBeTrue()
        ->and($adapter->uninstall())->toMatchArray(['status' => 'uninstalled']);
});

it('generates a webmail login URL for a tenant domain', function () {
    config()->set('app.url', 'https://panel.example.com');
    $adapter = new CliSnappyMailAdapter(Mockery::mock(LcCliService::class));

    expect($adapter->loginUrl(new DomainName('tenant.org')))
        ->toBe('https://panel.example.com/?domain=tenant.org');
});

it('fails fast with LcCliException when webmail-install returns an error', function () {
    $cli = Mockery::mock(LcCliService::class);
    $cli->shouldReceive('runMail')
        ->once()
        ->with('webmail-install', ['--driver=snappymail', '--hostname=mail.example.com'])
        ->andReturn(['status' => 'error', 'message' => 'OLS reload failed']);

    $adapter = new CliSnappyMailAdapter($cli);

    expect(fn () => $adapter->install('mail.example.com'))
        ->toThrow(LcCliException::class, 'OLS reload failed');
});

it('dispatches InstallWebmailJob when installing mail_webmail module', function () {
    Queue::fake();

    $actor = User::factory()->create(['username' => 'admin_webmail']);
    $moduleDriver = Mockery::mock(ModuleDriverInterface::class);
    $auditLogger = Mockery::mock(AuditLoggerInterface::class);
    $auditLogger->shouldReceive('log')->once();

    $action = new InstallModuleAction($moduleDriver, $auditLogger);
    $res = $action->handle('mail_webmail', $actor, '127.0.0.1');

    expect($res)->toMatchArray(['status' => 'queued', 'module' => 'mail_webmail']);
    Queue::assertPushed(InstallWebmailJob::class);
});

it('transitions module_install_mail_webmail cache state in InstallWebmailJob', function () {
    $driver = Mockery::mock(WebmailDriverInterface::class);
    $driver->shouldReceive('install')
        ->once()
        ->with('mail.example.com')
        ->andReturn(['status' => 'installed']);

    $job = new InstallWebmailJob('mail.example.com');
    $job->handle($driver);

    expect(Cache::get('module_install_mail_webmail'))->toBe('active')
        ->and(Cache::get('module_install_mail_webmail_error'))->toBeNull();
});

it('calls WebmailDriverInterface::uninstall when removing mail_webmail module', function () {
    $actor = User::factory()->create(['username' => 'admin_webmail']);
    $moduleDriver = Mockery::mock(ModuleDriverInterface::class);
    $auditLogger = Mockery::mock(AuditLoggerInterface::class);
    $auditLogger->shouldReceive('log')->once();

    $webmailDriver = Mockery::mock(WebmailDriverInterface::class);
    $webmailDriver->shouldReceive('uninstall')
        ->once()
        ->andReturn(['status' => 'uninstalled']);
    app()->instance(WebmailDriverInterface::class, $webmailDriver);

    $action = new RemoveModuleAction($moduleDriver, $auditLogger);
    $res = $action->handle('mail_webmail', $actor, '127.0.0.1');

    expect($res)->toMatchArray(['status' => 'uninstalled']);
});
