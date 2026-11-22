<?php

declare(strict_types=1);

use App\Domains\Dns\ValueObjects\UpdateZoneModePayload;

it('normalizes zone ID, kind, masters, ALLOW-AXFR-FROM, and ALSO-NOTIFY lists', function (): void {
    $primaryPayload = new UpdateZoneModePayload(
        zoneId: 'Example.com',
        kind: 'primary',
        masters: '198.51.100.10',
        allowAxfrFrom: '198.51.100.20, 203.0.113.0/24; 2001:db8::/64',
        alsoNotify: '198.51.100.25, 203.0.113.30:5300, [2001:db8::10]:53',
        confirmOverwrite: false,
    );

    expect($primaryPayload->zoneId)->toBe('example.com.')
        ->and($primaryPayload->kind)->toBe('Primary')
        ->and($primaryPayload->masters)->toBe([])
        ->and($primaryPayload->allowAxfrFrom)->toBe([
            '198.51.100.20',
            '203.0.113.0/24',
            '2001:db8::/64',
        ])
        ->and($primaryPayload->alsoNotify)->toBe([
            '198.51.100.25',
            '203.0.113.30:5300',
            '[2001:db8::10]:53',
        ])
        ->and($primaryPayload->toPowerDnsZonePayload())->toBe([
            'kind' => 'Primary',
            'masters' => [],
            'soa_edit_api' => 'DEFAULT',
            'api_rectify' => true,
        ]);

    $secondaryPayload = new UpdateZoneModePayload(
        zoneId: 'replica.example.com.',
        kind: 'Secondary',
        masters: '203.0.113.10, 198.51.100.53:53, [2001:db8::1]:5300',
        confirmOverwrite: true,
    );

    expect($secondaryPayload->kind)->toBe('Secondary')
        ->and($secondaryPayload->masters)->toBe([
            '203.0.113.10',
            '198.51.100.53:53',
            '[2001:db8::1]:5300',
        ])
        ->and($secondaryPayload->confirmOverwrite)->toBeTrue()
        ->and($secondaryPayload->toPowerDnsZonePayload())->toBe([
            'kind' => 'Secondary',
            'masters' => [
                '203.0.113.10',
                '198.51.100.53:53',
                '[2001:db8::1]:5300',
            ],
        ]);
});

it('rejects Secondary mode without valid upstream masters and rejects malformed ALLOW-AXFR-FROM or ALSO-NOTIFY entries', function (): void {
    expect(fn () => new UpdateZoneModePayload(
        zoneId: 'example.com.',
        kind: 'Secondary',
        masters: [],
    ))->toThrow(InvalidArgumentException::class, 'Secondary zones require at least one valid upstream primary');

    expect(fn () => new UpdateZoneModePayload(
        zoneId: 'example.com.',
        kind: 'Secondary',
        masters: ['not-an-ip:53'],
    ))->toThrow(InvalidArgumentException::class, 'Invalid upstream primary IP or IP:port entry');

    expect(fn () => new UpdateZoneModePayload(
        zoneId: 'example.com.',
        kind: 'Primary',
        allowAxfrFrom: ['192.0.2.1/99'],
    ))->toThrow(InvalidArgumentException::class, "Invalid IP or CIDR '192.0.2.1/99' in ALLOW-AXFR-FROM.");

    expect(fn () => new UpdateZoneModePayload(
        zoneId: 'example.com.',
        kind: 'Primary',
        alsoNotify: ['203.0.113.10:99999'],
    ))->toThrow(InvalidArgumentException::class, "Invalid IP or IP:port '203.0.113.10:99999' in ALSO-NOTIFY.");
});
