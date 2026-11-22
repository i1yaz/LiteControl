<?php

declare(strict_types=1);

use App\Domains\Dns\ValueObjects\ZoneNameNormalizer;

beforeEach(function (): void {
    $this->normalizer = new ZoneNameNormalizer;
});

it('normalizes forward zone names to lowercase canonical FQDNs with trailing dot', function (): void {
    expect($this->normalizer->normalizeForwardZone('Example.COM'))->toBe('example.com.')
        ->and($this->normalizer->normalizeForwardZone('sub.example.com.'))->toBe('sub.example.com.')
        ->and($this->normalizer->normalizeForwardZone('  my-domain.co.uk  '))->toBe('my-domain.co.uk.');
});

it('converts IDN unicode forward zones to Punycode and back to UTF-8 for display', function (): void {
    $punycode = $this->normalizer->normalizeForwardZone('münchen.de');

    expect($punycode)->toBe('xn--mnchen-3ya.de.')
        ->and($this->normalizer->toDisplayUtf8($punycode))->toBe('münchen.de');
});

it('rejects invalid forward zone names', function (string $invalidInput): void {
    expect(fn () => $this->normalizer->normalizeForwardZone($invalidInput))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'empty string' => [''],
    'only dot' => ['.'],
    'single label' => ['localhost'],
    'contains whitespace' => ['exam ple.com'],
    'double dot' => ['example..com'],
    'leading hyphen' => ['-example.com'],
    'trailing hyphen' => ['example-.com'],
    'label over 63 chars' => [str_repeat('a', 64).'.com'],
]);

it('converts IPv4 CIDR blocks and prefixes into reversed in-addr.arpa. zones', function (): void {
    expect($this->normalizer->fromIpv4Cidr('192.0.2.0/24'))->toBe('2.0.192.in-addr.arpa.')
        ->and($this->normalizer->fromIpv4Cidr('198.51.0.0/16'))->toBe('51.198.in-addr.arpa.')
        ->and($this->normalizer->fromIpv4Cidr('10.0.0.0/8'))->toBe('10.in-addr.arpa.')
        ->and($this->normalizer->fromIpv4Cidr('192.0.2.15/32'))->toBe('15.2.0.192.in-addr.arpa.')
        ->and($this->normalizer->fromIpv4Cidr('192.0.2'))->toBe('2.0.192.in-addr.arpa.')
        ->and($this->normalizer->fromIpv4Cidr('2.0.192.in-addr.arpa'))->toBe('2.0.192.in-addr.arpa.')
        ->and($this->normalizer->isReverseZone('2.0.192.in-addr.arpa.'))->toBeTrue()
        ->and($this->normalizer->reverseType('2.0.192.in-addr.arpa.'))->toBe('ipv4');
});

it('rejects non-octet-aligned or malformed IPv4 reverse zone inputs', function (string $invalidInput): void {
    expect(fn () => $this->normalizer->fromIpv4Cidr($invalidInput))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'empty' => [''],
    'non-octet CIDR /25' => ['192.0.2.0/25'],
    'bad IPv4' => ['999.0.2.0/24'],
    'invalid arpa octets' => ['300.0.192.in-addr.arpa.'],
]);

it('converts IPv6 CIDR prefixes into nibble-inverted ip6.arpa. zones', function (): void {
    expect($this->normalizer->fromIpv6Cidr('2001:db8:abcd::/48'))
        ->toBe('d.c.b.a.8.b.d.0.1.0.0.2.ip6.arpa.')
        ->and($this->normalizer->fromIpv6Cidr('2001:db8::/32'))
        ->toBe('8.b.d.0.1.0.0.2.ip6.arpa.')
        ->and($this->normalizer->fromIpv6Cidr('2001:db8:abcd:0012::/64'))
        ->toBe('2.1.0.0.d.c.b.a.8.b.d.0.1.0.0.2.ip6.arpa.')
        ->and($this->normalizer->fromIpv6Cidr('d.c.b.a.8.b.d.0.1.0.0.2.ip6.arpa'))
        ->toBe('d.c.b.a.8.b.d.0.1.0.0.2.ip6.arpa.')
        ->and($this->normalizer->isReverseZone('d.c.b.a.8.b.d.0.1.0.0.2.ip6.arpa.'))->toBeTrue()
        ->and($this->normalizer->reverseType('d.c.b.a.8.b.d.0.1.0.0.2.ip6.arpa.'))->toBe('ipv6');
});

it('rejects non-nibble-aligned or invalid IPv6 reverse zone inputs', function (string $invalidInput): void {
    expect(fn () => $this->normalizer->fromIpv6Cidr($invalidInput))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'empty' => [''],
    'missing prefix length' => ['2001:db8:abcd::'],
    'non-nibble prefix /50' => ['2001:db8:abcd::/50'],
    'invalid IPv6' => ['2001:db8:zzzz::/48'],
    'invalid ip6.arpa nibble' => ['z.c.b.a.ip6.arpa.'],
]);
