<?php

declare(strict_types=1);

use App\Domains\Dns\ValueObjects\AutoprimaryEntry;

it('normalizes valid IPv4/IPv6 autoprimary entries with trailing-dot nameserver FQDNs', function (): void {
    $ipv4Entry = new AutoprimaryEntry(
        ip: ' 203.0.113.10 ',
        nameserver: 'NS1.PrimaryHost.Example.COM',
        account: ' primary-dc1 ',
    );

    expect($ipv4Entry->ip)->toBe('203.0.113.10')
        ->and($ipv4Entry->nameserver)->toBe('ns1.primaryhost.example.com.')
        ->and($ipv4Entry->account)->toBe('primary-dc1')
        ->and($ipv4Entry->toPowerDnsPayload())->toBe([
            'ip' => '203.0.113.10',
            'nameserver' => 'ns1.primaryhost.example.com.',
            'account' => 'primary-dc1',
        ]);

    $ipv6Entry = new AutoprimaryEntry(
        ip: '2001:db8::53',
        nameserver: 'ns2.example.com.',
        account: '',
    );

    expect($ipv6Entry->ip)->toBe('2001:db8::53')
        ->and($ipv6Entry->nameserver)->toBe('ns2.example.com.')
        ->and($ipv6Entry->account)->toBe('');
});

it('rejects invalid IP addresses, invalid nameservers, or malformed account names in AutoprimaryEntry', function (): void {
    expect(fn () => new AutoprimaryEntry(ip: 'not-an-ip', nameserver: 'ns1.example.com.'))
        ->toThrow(InvalidArgumentException::class, 'not a valid IPv4 or IPv6 address');

    expect(fn () => new AutoprimaryEntry(ip: '203.0.113.10', nameserver: '   '))
        ->toThrow(InvalidArgumentException::class, 'nameserver FQDN is required');

    expect(fn () => new AutoprimaryEntry(ip: '203.0.113.10', nameserver: 'ns1.example.com.', account: 'bad account spaces!'))
        ->toThrow(InvalidArgumentException::class, 'may only contain letters, digits, dots, underscores, and hyphens');

    expect(fn () => new AutoprimaryEntry(ip: '203.0.113.10', nameserver: 'ns1.example.com.', account: str_repeat('a', 41)))
        ->toThrow(InvalidArgumentException::class, 'cannot exceed 40 characters');
});
