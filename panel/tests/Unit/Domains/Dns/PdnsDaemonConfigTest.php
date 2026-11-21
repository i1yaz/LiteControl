<?php

declare(strict_types=1);

use App\Domains\Dns\ValueObjects\PdnsDaemonConfig;

it('constructs valid loopback PdnsDaemonConfig with defaults', function (): void {
    $config = new PdnsDaemonConfig;

    expect($config->host)->toBe('127.0.0.1')
        ->and($config->port)->toBe(8081)
        ->and($config->webserverAllowFrom)->toBe('127.0.0.1,::1')
        ->and($config->isLoopback())->toBeTrue()
        ->and($config->primaryEnabled)->toBeTrue()
        ->and($config->secondaryEnabled)->toBeFalse()
        ->and($config->autosecondaryEnabled)->toBeFalse();
});

it('builds deterministic CLI payload for lc-cli pdns apply-config', function (): void {
    $apiKey = str_repeat('ab', 32);

    $config = new PdnsDaemonConfig(
        host: '127.0.0.1',
        port: 8081,
        webserverAllowFrom: '127.0.0.1, ::1',
        webserverPassword: null,
        primaryEnabled: true,
        secondaryEnabled: true,
        autosecondaryEnabled: false,
        allowAxfrIps: '198.51.100.10, 203.0.113.0/24',
        alsoNotify: '198.51.100.10:5300, 203.0.113.20',
    );

    expect($config->toCliPayload($apiKey))->toBe([
        'api_key' => $apiKey,
        'webserver_address' => '127.0.0.1',
        'webserver_port' => 8081,
        'webserver_allow_from' => '127.0.0.1,::1',
        'primary' => true,
        'secondary' => true,
        'autosecondary' => false,
        'allow_axfr_ips' => '198.51.100.10,203.0.113.0/24',
        'also_notify' => '198.51.100.10:5300,203.0.113.20',
    ]);
});

it('rejects unrestricted 0.0.0.0/0 or ::/0 when webserver bind address is non-loopback', function (): void {
    expect(fn () => new PdnsDaemonConfig(
        host: '192.0.2.10',
        port: 8081,
        webserverAllowFrom: '127.0.0.1,0.0.0.0/0',
        webserverPassword: 'strong-password',
    ))->toThrow(InvalidArgumentException::class, 'cannot contain unrestricted');
});

it('requires webserver_password when webserver bind address is non-loopback', function (): void {
    expect(fn () => new PdnsDaemonConfig(
        host: '192.0.2.10',
        port: 8081,
        webserverAllowFrom: '192.0.2.0/24',
        webserverPassword: null,
        hasExistingWebserverPassword: false,
    ))->toThrow(InvalidArgumentException::class, 'webserver_password is required');

    $validWithExisting = new PdnsDaemonConfig(
        host: '192.0.2.10',
        port: 8081,
        webserverAllowFrom: '192.0.2.0/24',
        webserverPassword: null,
        hasExistingWebserverPassword: true,
    );

    expect($validWithExisting->isLoopback())->toBeFalse()
        ->and($validWithExisting->host)->toBe('192.0.2.10');
});

it('rejects invalid IP or CIDR entries in allowlists and also_notify', function (): void {
    expect(fn () => new PdnsDaemonConfig(
        host: 'not-an-ip',
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => new PdnsDaemonConfig(
        allowAxfrIps: '203.0.113.0/99',
    ))->toThrow(InvalidArgumentException::class, 'allow_axfr_ips');

    expect(fn () => new PdnsDaemonConfig(
        alsoNotify: '203.0.113.10:99999',
    ))->toThrow(InvalidArgumentException::class, 'also_notify');
});
