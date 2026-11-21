<?php

declare(strict_types=1);

use App\Domains\Mail\Contracts\MailServerDriverInterface;
use App\Domains\Mail\Infrastructure\CliMailServerAdapter;
use App\Exceptions\System\LcCliException;
use App\Support\Cli\LcCliService;
use Tests\TestCase;

uses(TestCase::class);

it('binds MailServerDriverInterface to CliMailServerAdapter', function () {
    expect(app(MailServerDriverInterface::class))
        ->toBeInstanceOf(CliMailServerAdapter::class);
});

it('invokes lc-mail subcommands through LcCliService', function () {
    $cli = Mockery::mock(LcCliService::class);
    $cli->shouldReceive('runMail')
        ->once()
        ->with('install-core', ['--hostname=mail.example.com'])
        ->andReturn(['status' => 'success', 'data' => ['installed' => true]]);
    $cli->shouldReceive('runMail')
        ->once()
        ->with('uninstall-core', ['--purge-data'])
        ->andReturn(['status' => 'success', 'data' => ['installed' => false]]);
    $cli->shouldReceive('runMail')
        ->once()
        ->with('reconcile', ['--scope=dkim'])
        ->andReturn(['status' => 'success', 'data' => ['reconciled' => true]]);
    $cli->shouldReceive('runMail')
        ->once()
        ->with('evict-session', ['--email=alice@example.com'])
        ->andReturn(['status' => 'success', 'data' => ['evicted' => true]]);
    $cli->shouldReceive('runMail')
        ->once()
        ->with('dkim-generate', ['--domain=example.com', '--selector=lc2026', '--algo=rsa2048'])
        ->andReturn(['status' => 'success', 'data' => ['key_sha256' => 'abc']]);
    $cli->shouldReceive('runMail')
        ->once()
        ->with('dkim-remove', ['--domain=example.com', '--selector=lc2026'])
        ->andReturn(['status' => 'success', 'data' => ['removed' => true]]);
    $cli->shouldReceive('runMail')
        ->once()
        ->with('purge-mailbox', ['--domain=example.com', '--user=alice'])
        ->andReturn(['status' => 'success', 'data' => ['purged' => true]]);
    $cli->shouldReceive('runMail')
        ->once()
        ->with('purge-domain', ['--domain=example.com'])
        ->andReturn(['status' => 'success', 'data' => ['purged' => true]]);
    $cli->shouldReceive('runMail')
        ->once()
        ->with('quota-usage', ['--domain=example.com'])
        ->andReturn(['status' => 'success', 'data' => ['alice@example.com' => '4096']]);

    $adapter = new CliMailServerAdapter($cli);

    expect($adapter->installCore('mail.example.com'))->toBe(['installed' => true])
        ->and($adapter->uninstallCore(true))->toBe(['installed' => false])
        ->and($adapter->reconcile('dkim'))->toBe(['reconciled' => true])
        ->and($adapter->evictSession('alice@example.com'))->toBe(['evicted' => true])
        ->and($adapter->generateDkimKey('example.com', 'lc2026'))->toBe(['key_sha256' => 'abc'])
        ->and($adapter->removeDkimKey('example.com', 'lc2026'))->toBe(['removed' => true])
        ->and($adapter->purgeMailbox('example.com', 'alice'))->toBe(['purged' => true])
        ->and($adapter->purgeDomain('example.com'))->toBe(['purged' => true])
        ->and($adapter->readQuotaUsage('example.com'))->toBe(['alice@example.com' => 4096]);
});

it('fails fast with LcCliException when lc-mail returns an error', function () {
    $cli = Mockery::mock(LcCliService::class);
    $cli->shouldReceive('runMail')
        ->once()
        ->with('install-core', ['--hostname=mail.example.com'])
        ->andReturn(['status' => 'error', 'message' => 'Postfix version < 3.4']);

    $adapter = new CliMailServerAdapter($cli);

    expect(fn () => $adapter->installCore('mail.example.com'))
        ->toThrow(LcCliException::class, 'Postfix version < 3.4');
});
