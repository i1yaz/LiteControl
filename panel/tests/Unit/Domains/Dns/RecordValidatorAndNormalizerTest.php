<?php

declare(strict_types=1);

use App\Domains\Dns\ValueObjects\RecordNormalizer;
use App\Domains\Dns\ValueObjects\RecordValidator;
use App\Domains\Dns\ValueObjects\RrsetPayloadBuilder;

beforeEach(function (): void {
    $this->normalizer = new RecordNormalizer;
    $this->validator = new RecordValidator($this->normalizer);
    $this->builder = new RrsetPayloadBuilder($this->normalizer, $this->validator);
});

describe('RecordNormalizer — Owner Name & IDN Normalization', function (): void {
    it('normalizes relative labels, @, wildcards, service labels, and FQDNs to canonical trailing-dot FQDNs', function (): void {
        expect($this->normalizer->normalizeRecordName('@', 'example.com'))->toBe('example.com.')
            ->and($this->normalizer->normalizeRecordName('www', 'example.com.'))->toBe('www.example.com.')
            ->and($this->normalizer->normalizeRecordName('mail.sub', 'example.com'))->toBe('mail.sub.example.com.')
            ->and($this->normalizer->normalizeRecordName('www.example.com', 'example.com.'))->toBe('www.example.com.')
            ->and($this->normalizer->normalizeRecordName('WWW.EXAMPLE.COM.', 'example.com.'))->toBe('www.example.com.')
            ->and($this->normalizer->normalizeRecordName('example.com', 'example.com.'))->toBe('example.com.')
            ->and($this->normalizer->normalizeRecordName('example.com.', 'example.com.'))->toBe('example.com.')
            ->and($this->normalizer->normalizeRecordName('*', 'example.com.'))->toBe('*.example.com.')
            ->and($this->normalizer->normalizeRecordName('*.sub', 'example.com.'))->toBe('*.sub.example.com.')
            ->and($this->normalizer->normalizeRecordName('_dmarc', 'example.com.'))->toBe('_dmarc.example.com.')
            ->and($this->normalizer->normalizeRecordName('_sip._tcp', 'example.com.'))->toBe('_sip._tcp.example.com.')
            ->and($this->normalizer->normalizeRecordName('default._domainkey', 'example.com.'))->toBe('default._domainkey.example.com.')
            ->and($this->normalizer->normalizeRecordName('15', '2.0.192.in-addr.arpa.'))->toBe('15.2.0.192.in-addr.arpa.');
    });

    it('converts IDN unicode record labels and domains to Punycode and back to UTF-8 display strings', function (): void {
        $normalized = $this->normalizer->normalizeRecordName('bücher.shop', 'münchen.de');

        expect($normalized)->toBe('xn--bcher-kva.shop.xn--mnchen-3ya.de.')
            ->and($this->normalizer->toDisplayUtf8($normalized))->toBe('bücher.shop.münchen.de');
    });

    it('rejects record names with leading/trailing whitespace, non-leftmost wildcards, out-of-zone FQDNs, or overlong labels', function (string $invalidName): void {
        expect(fn () => $this->normalizer->normalizeRecordName($invalidName, 'example.com.'))
            ->toThrow(InvalidArgumentException::class);
    })->with([
        'empty' => [''],
        'leading space' => [' www'],
        'trailing space' => ['www '],
        'internal space' => ['my host'],
        'double dot' => ['sub..www'],
        'leading dot' => ['.www'],
        'mid-label wildcard' => ['sub.*.example.com'],
        'inline wildcard char' => ['w*w'],
        'out of zone trailing-dot FQDN' => ['www.otherdomain.org.'],
        'label over 63 chars' => [str_repeat('a', 64)],
        'total over 253 chars' => [
            str_repeat('a', 60).'.'.str_repeat('b', 60).'.'.str_repeat('c', 60).'.'.str_repeat('d', 60),
        ],
    ]);
});

describe('RecordNormalizer — All 10 Supported Record Types', function (): void {
    it('validates A records with FILTER_FLAG_IPV4 and rejects whitespace or IPv6', function (): void {
        expect($this->normalizer->normalizeARecord('203.0.113.10'))->toBe('203.0.113.10')
            ->and($this->normalizer->normalizeContent('A', '192.0.2.1'))->toBe('192.0.2.1');

        foreach (['', ' 203.0.113.10', '203.0.113.10 ', '999.0.2.1', '2001:db8::1', 'example.com'] as $badIp) {
            expect(fn () => $this->normalizer->normalizeARecord($badIp))->toThrow(InvalidArgumentException::class);
        }
    });

    it('validates AAAA records with FILTER_FLAG_IPV6 and canonicalizes compressed IPv6 representation', function (): void {
        expect($this->normalizer->normalizeAaaaRecord('2001:0DB8:0000:0000:0000:0000:0000:0010'))->toBe('2001:db8::10')
            ->and($this->normalizer->normalizeContent('AAAA', '2001:db8::1'))->toBe('2001:db8::1');

        foreach (['', ' 2001:db8::1', '2001:db8::1 ', '203.0.113.1', '2001:db8::zzzz'] as $badIp) {
            expect(fn () => $this->normalizer->normalizeAaaaRecord($badIp))->toThrow(InvalidArgumentException::class);
        }
    });

    it('normalizes CNAME, NS, and PTR targets to canonical trailing-dot FQDNs with IDN support', function (): void {
        expect($this->normalizer->normalizeCnameRecord('app.example.com', 'example.com.'))->toBe('app.example.com.')
            ->and($this->normalizer->normalizeCnameRecord('@', 'example.com.'))->toBe('example.com.')
            ->and($this->normalizer->normalizeCnameRecord('shop', 'example.com.'))->toBe('shop.example.com.')
            ->and($this->normalizer->normalizeCnameRecord('münchen.de'))->toBe('xn--mnchen-3ya.de.')
            ->and($this->normalizer->normalizeNsRecord('NS1.PANEL.COM'))->toBe('ns1.panel.com.')
            ->and($this->normalizer->normalizePtrRecord('mail.example.com'))->toBe('mail.example.com.');

        expect(fn () => $this->normalizer->normalizeNsRecord('ns1.panel.com '))
            ->toThrow(InvalidArgumentException::class);
    });

    it('serializes MX records from priority + mail server or string input and validates priority bounds (0-65535)', function (): void {
        expect($this->normalizer->normalizeMxRecord(10, 'mail.example.com'))->toBe('10 mail.example.com.')
            ->and($this->normalizer->normalizeMxRecord(0, 'mx1', 'example.com.'))->toBe('0 mx1.example.com.')
            ->and($this->normalizer->normalizeMxRecord(0, '.'))->toBe('0 .') // Null MX (RFC 7505)
            ->and($this->normalizer->normalizeContent('MX', ['priority' => 20, 'target' => 'mx2.münchen.de']))
            ->toBe('20 mx2.xn--mnchen-3ya.de.')
            ->and($this->normalizer->normalizeContent('MX', '30 backup.example.com', 'example.com.'))
            ->toBe('30 backup.example.com.');

        expect(fn () => $this->normalizer->normalizeMxRecord(-1, 'mail.example.com'))
            ->toThrow(InvalidArgumentException::class)
            ->and(fn () => $this->normalizer->normalizeMxRecord(65536, 'mail.example.com'))
            ->toThrow(InvalidArgumentException::class)
            ->and(fn () => $this->normalizer->normalizeContent('MX', 'mail.example.com'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('serializes SRV records from priority, weight, port, and target and validates 0-65535 bounds', function (): void {
        expect($this->normalizer->normalizeSrvRecord(10, 60, 5060, 'sipserver.example.com'))
            ->toBe('10 60 5060 sipserver.example.com.')
            ->and($this->normalizer->normalizeContent('SRV', [
                'priority' => 5,
                'weight' => 100,
                'port' => 443,
                'target' => 'pbx',
            ], 'example.com.'))->toBe('5 100 443 pbx.example.com.')
            ->and($this->normalizer->normalizeContent('SRV', '0 5 5269 xmpp.example.com'))
            ->toBe('0 5 5269 xmpp.example.com.');

        expect(fn () => $this->normalizer->normalizeSrvRecord(10, 60, 70000, 'sip.example.com'))
            ->toThrow(InvalidArgumentException::class)
            ->and(fn () => $this->normalizer->normalizeSrvRecord(10, -5, 5060, 'sip.example.com'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('serializes CAA records with flags (0 or 128), tag (issue, issuewild, iodef), and escaped quoted value', function (): void {
        expect($this->normalizer->normalizeCaaRecord(0, 'issue', 'letsencrypt.org'))
            ->toBe('0 issue "letsencrypt.org"')
            ->and($this->normalizer->normalizeCaaRecord(128, 'ISSUEWILD', 'sectigo.com; cansignhttpexchanges=yes'))
            ->toBe('128 issuewild "sectigo.com; cansignhttpexchanges=yes"')
            ->and($this->normalizer->normalizeContent('CAA', [
                'flags' => 0,
                'tag' => 'iodef',
                'value' => 'mailto:security@example.com',
            ]))->toBe('0 iodef "mailto:security@example.com"')
            ->and($this->normalizer->normalizeContent('CAA', '0 issue "letsencrypt.org"'))
            ->toBe('0 issue "letsencrypt.org"');

        expect(fn () => $this->normalizer->normalizeCaaRecord(1, 'issue', 'letsencrypt.org'))
            ->toThrow(InvalidArgumentException::class)
            ->and(fn () => $this->normalizer->normalizeCaaRecord(0, 'unknown', 'letsencrypt.org'))
            ->toThrow(InvalidArgumentException::class)
            ->and(fn () => $this->normalizer->normalizeCaaRecord(0, 'issue', ''))
            ->toThrow(InvalidArgumentException::class);
    });

    it('wraps TXT records in double quotes, escapes internal quotes and backslashes, and auto-splits >255-byte DKIM strings', function (): void {
        // Standard SPF string
        expect($this->normalizer->normalizeTxtRecord('v=spf1 ip4:203.0.113.10 -all'))
            ->toBe('"v=spf1 ip4:203.0.113.10 -all"');

        // Already-quoted SPF string stays single-quoted without double wrapping
        expect($this->normalizer->normalizeTxtRecord('"v=spf1 a mx ~all"'))
            ->toBe('"v=spf1 a mx ~all"');

        // Internal quotes and backslashes are escaped properly
        expect($this->normalizer->normalizeTxtRecord('test "quoted" and \\backslash'))
            ->toBe('"test \\"quoted\\" and \\\\backslash"');

        // 500-char 2048-bit DKIM record splits cleanly into space-separated 255-byte quoted chunks
        $dkimBody = 'v=DKIM1; k=rsa; p='.str_repeat('MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA', 11);
        expect(strlen($dkimBody))->toBeGreaterThan(480);

        $normalizedDkim = $this->normalizer->normalizeTxtRecord($dkimBody);
        $matched = preg_match_all('/"([^"]*)"/', $normalizedDkim, $chunkMatches);

        expect($matched)->toBe(2)
            ->and(strlen($chunkMatches[1][0]))->toBe(255)
            ->and(strlen($chunkMatches[1][1]))->toBe(strlen($dkimBody) - 255)
            ->and($chunkMatches[1][0].$chunkMatches[1][1])->toBe($dkimBody);

        // Idempotent when re-normalizing an already split multi-chunk TXT record
        expect($this->normalizer->normalizeTxtRecord($normalizedDkim))->toBe($normalizedDkim);
    });

    it('normalizes SOA records and converts hostmaster @ email (including dotted local parts) to canonical RNAME format', function (): void {
        expect($this->normalizer->normalizeSoaRecord(
            primaryNs: 'ns1.example.com',
            hostmaster: 'hostmaster@example.com',
            serial: 2026112101,
            refresh: 10800,
            retry: 3600,
            expire: 604800,
            minimum: 3600,
        ))->toBe('ns1.example.com. hostmaster.example.com. 2026112101 10800 3600 604800 3600');

        // Local part with dot before @ is escaped as \. per RFC 1035 §8
        expect($this->normalizer->normalizeSoaRecord(
            primaryNs: 'ns1.example.com.',
            hostmaster: 'dns.admin@example.com',
            serial: 2026112102,
            refresh: 10800,
            retry: 3600,
            expire: 604800,
            minimum: 3600,
        ))->toBe('ns1.example.com. dns\\.admin.example.com. 2026112102 10800 3600 604800 3600');

        // String-formatted SOA
        expect($this->normalizer->normalizeContent(
            'SOA',
            'ns1.example.com hostmaster.example.com 2026112101 10800 3600 604800 3600',
            'example.com.'
        ))->toBe('ns1.example.com. hostmaster.example.com. 2026112101 10800 3600 604800 3600');
    });
});

describe('RecordValidator — CNAME Apex & Conflict Rules (RFC 1034 / RFC 1912)', function (): void {
    it('rejects CNAME at the zone apex (@ or zone root FQDN)', function (): void {
        expect(fn () => $this->validator->assertCnameNotAtApex('example.com.', '@', 'CNAME'))
            ->toThrow(InvalidArgumentException::class, 'not permitted at the zone apex')
            ->and(fn () => $this->validator->assertCnameNotAtApex('example.com.', 'example.com.', 'CNAME'))
            ->toThrow(InvalidArgumentException::class, 'not permitted at the zone apex')
            ->and(fn () => $this->validator->validateAndNormalizeRecord('example.com', '@', 'CNAME', 'target.example.com'))
            ->toThrow(InvalidArgumentException::class, 'not permitted at the zone apex');
    });

    it('rejects adding a CNAME on a name that already owns any other record type', function (): void {
        $existingRrsets = [
            [
                'name' => 'app.example.com.',
                'type' => 'A',
                'ttl' => 3600,
                'records' => [
                    ['content' => '203.0.113.10', 'disabled' => false],
                ],
            ],
            [
                'name' => 'app.example.com.',
                'type' => 'TXT',
                'ttl' => 3600,
                'records' => [
                    ['content' => '"v=spf1 -all"', 'disabled' => false],
                ],
            ],
        ];

        expect(fn () => $this->validator->assertNoCnameConflict('example.com.', 'app', 'CNAME', $existingRrsets))
            ->toThrow(InvalidArgumentException::class, 'A, TXT record(s) already exist');
    });

    it('rejects adding any non-CNAME record on a name where a CNAME already exists', function (): void {
        $existingRrsets = [
            [
                'name' => 'blog.example.com.',
                'type' => 'CNAME',
                'ttl' => 300,
                'records' => [
                    ['content' => 'hosting.provider.net.', 'disabled' => false],
                ],
            ],
        ];

        expect(fn () => $this->validator->assertNoCnameConflict('example.com.', 'blog.example.com', 'A', $existingRrsets))
            ->toThrow(InvalidArgumentException::class, 'a CNAME record already exists')
            ->and(fn () => $this->validator->assertNoCnameConflict('example.com.', 'blog', 'TXT', $existingRrsets))
            ->toThrow(InvalidArgumentException::class, 'a CNAME record already exists');
    });

    it('allows updating an existing CNAME record on the same name when no other record types exist', function (): void {
        $existingRrsets = [
            [
                'name' => 'blog.example.com.',
                'type' => 'CNAME',
                'ttl' => 300,
                'records' => [
                    ['content' => 'old.provider.net.', 'disabled' => false],
                ],
            ],
        ];

        $this->validator->assertNoCnameConflict('example.com.', 'blog', 'CNAME', $existingRrsets);
        expect(true)->toBeTrue();
    });

    it('validates TTL bounds (60 to 604800 seconds) and SOA apex placement', function (): void {
        expect($this->validator->validateTtl(60))->toBe(60)
            ->and($this->validator->validateTtl(604800))->toBe(604800);

        expect(fn () => $this->validator->validateTtl(59))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $this->validator->validateTtl(604801))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $this->validator->validateAndNormalizeRecord(
                'example.com.',
                'sub',
                'SOA',
                'ns1.example.com. hostmaster.example.com. 1 10800 3600 604800 3600'
            ))->toThrow(InvalidArgumentException::class, 'only be defined at the zone apex');
    });
});

describe('RrsetPayloadBuilder — REPLACE, DELETE, EXTEND, PRUNE & Batch PATCH Invariants', function (): void {
    it('builds REPLACE payloads with normalized records and optional comments', function (): void {
        $payload = $this->builder->buildReplace(
            zoneName: 'example.com',
            name: 'www',
            type: 'A',
            ttl: 300,
            records: [
                '203.0.113.10',
                ['content' => '203.0.113.11', 'disabled' => true],
            ],
            comments: [
                ['content' => 'Primary & failover web nodes', 'account' => 'admin'],
            ],
        );

        expect($payload)->toBe([
            'name' => 'www.example.com.',
            'type' => 'A',
            'ttl' => 300,
            'changetype' => 'REPLACE',
            'records' => [
                ['content' => '203.0.113.10', 'disabled' => false],
                ['content' => '203.0.113.11', 'disabled' => true],
            ],
            'comments' => [
                ['content' => 'Primary & failover web nodes', 'account' => 'admin'],
            ],
        ]);
    });

    it('enforces DELETE payload invariants: omits ttl completely and passes empty records and comments', function (): void {
        $deletePayload = $this->builder->buildDelete('example.com.', 'old-host', 'AAAA');

        expect($deletePayload)->toBe([
            'name' => 'old-host.example.com.',
            'type' => 'AAAA',
            'changetype' => 'DELETE',
            'records' => [],
            'comments' => [],
        ])
            ->and(array_key_exists('ttl', $deletePayload))->toBeFalse();

        // Rejects DELETE operation descriptor if ttl, records, or comments are provided
        expect(fn () => $this->builder->buildFromOperation('example.com.', [
            'name' => 'old-host',
            'type' => 'A',
            'changetype' => 'DELETE',
            'ttl' => 3600,
        ]))->toThrow(InvalidArgumentException::class, 'omit the ttl field')
            ->and(fn () => $this->builder->buildFromOperation('example.com.', [
                'name' => 'old-host',
                'type' => 'A',
                'changetype' => 'DELETE',
                'records' => [['content' => '203.0.113.10']],
            ]))->toThrow(InvalidArgumentException::class, 'empty records array')
            ->and(fn () => $this->builder->buildDelete('example.com.', '@', 'SOA'))
            ->toThrow(InvalidArgumentException::class, 'Cannot DELETE the zone SOA');
    });

    it('enforces EXTEND and PRUNE payload invariants: strictly one record and empty comments', function (): void {
        $extendPayload = $this->builder->buildExtend(
            zoneName: 'example.com',
            name: '_acme-challenge',
            type: 'TXT',
            ttl: 60,
            record: 'acme-token-xyz-123',
        );

        expect($extendPayload)->toBe([
            'name' => '_acme-challenge.example.com.',
            'type' => 'TXT',
            'ttl' => 60,
            'changetype' => 'EXTEND',
            'records' => [
                ['content' => '"acme-token-xyz-123"', 'disabled' => false],
            ],
            'comments' => [],
        ]);

        $prunePayload = $this->builder->buildPrune(
            zoneName: 'example.com',
            name: '_acme-challenge',
            type: 'TXT',
            record: 'acme-token-xyz-123',
        );

        expect($prunePayload)->toBe([
            'name' => '_acme-challenge.example.com.',
            'type' => 'TXT',
            'changetype' => 'PRUNE',
            'records' => [
                ['content' => '"acme-token-xyz-123"', 'disabled' => false],
            ],
            'comments' => [],
        ])
            ->and(array_key_exists('ttl', $prunePayload))->toBeFalse();

        // Rejects EXTEND/PRUNE with multiple records or non-empty comments
        expect(fn () => $this->builder->buildFromOperation('example.com.', [
            'name' => 'www',
            'type' => 'A',
            'ttl' => 300,
            'changetype' => 'EXTEND',
            'records' => ['203.0.113.10', '203.0.113.11'],
        ]))->toThrow(InvalidArgumentException::class, 'exactly one record')
            ->and(fn () => $this->builder->buildExtend(
                zoneName: 'example.com.',
                name: 'www',
                type: 'A',
                ttl: 300,
                record: '203.0.113.10',
                comments: [['content' => 'not allowed']],
            ))->toThrow(InvalidArgumentException::class, 'empty comments')
            ->and(fn () => $this->builder->buildPrune(
                zoneName: 'example.com.',
                name: 'www',
                type: 'A',
                record: '203.0.113.10',
                comments: [['content' => 'not allowed']],
            ))->toThrow(InvalidArgumentException::class, 'empty comments');
    });

    it('allows atomic batch PATCH replacing a CNAME with A + AAAA records while rejecting batches that leave a CNAME conflict', function (): void {
        $existingRrsets = [
            [
                'name' => 'shop.example.com.',
                'type' => 'CNAME',
                'ttl' => 300,
                'records' => [
                    ['content' => 'shopify.example.net.', 'disabled' => false],
                ],
            ],
        ];

        // Atomic swap: DELETE CNAME + REPLACE A + REPLACE AAAA on shop.example.com. succeeds
        $batch = $this->builder->buildBatchPatchPayload(
            zoneName: 'example.com',
            operations: [
                [
                    'name' => 'shop',
                    'type' => 'CNAME',
                    'changetype' => 'DELETE',
                ],
                [
                    'name' => 'shop',
                    'type' => 'A',
                    'ttl' => 300,
                    'changetype' => 'REPLACE',
                    'records' => ['203.0.113.55'],
                ],
                [
                    'name' => 'shop',
                    'type' => 'AAAA',
                    'ttl' => 300,
                    'changetype' => 'REPLACE',
                    'records' => ['2001:db8::55'],
                ],
            ],
            existingRrsets: $existingRrsets,
        );

        expect($batch['rrsets'])->toHaveCount(3)
            ->and($batch['rrsets'][0]['changetype'])->toBe('DELETE')
            ->and($batch['rrsets'][1]['type'])->toBe('A')
            ->and($batch['rrsets'][2]['type'])->toBe('AAAA');

        // Without deleting the existing CNAME first, adding an A record on shop.example.com. fails
        expect(fn () => $this->builder->buildBatchPatchPayload(
            zoneName: 'example.com',
            operations: [
                [
                    'name' => 'shop',
                    'type' => 'A',
                    'ttl' => 300,
                    'changetype' => 'REPLACE',
                    'records' => ['203.0.113.55'],
                ],
            ],
            existingRrsets: $existingRrsets,
        ))->toThrow(InvalidArgumentException::class, 'CNAME exclusivity violation');
    });
});
