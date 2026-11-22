<?php

declare(strict_types=1);

use App\Domains\Dns\Actions\RenderZoneTemplateAction;
use App\Domains\Dns\Models\DnsZoneTemplate;
use App\Domains\Dns\ValueObjects\DefaultNameserverEntry;
use App\Domains\Dns\ValueObjects\ZoneTemplateRecordData;
use App\Domains\Dns\ValueObjects\ZoneTemplateVariables;

beforeEach(function (): void {
    $this->action = new RenderZoneTemplateAction;
});

it('deterministically replaces {{DOMAIN}}, {{IPV4}}, {{IPV6}}, {{NS1}}, and {{NS2}} placeholders and groups RRSets', function (): void {
    $variables = new ZoneTemplateVariables(
        domain: 'example.com',
        ipv4: '203.0.113.50',
        ipv6: '2001:db8::50',
        ns1: 'ns1.litecontrol.test',
        ns2: 'ns2.litecontrol.test.',
    );

    $records = DnsZoneTemplate::defaultRecordsPayload();
    $rrsets = $this->action->handle($records, $variables);

    $byKey = [];
    foreach ($rrsets as $rrset) {
        $byKey[$rrset['name'].'|'.$rrset['type']] = $rrset;
    }

    // NS records grouped into a single apex NS RRSet
    expect($byKey)->toHaveKey('example.com.|NS')
        ->and($byKey['example.com.|NS'])->toMatchArray([
            'name' => 'example.com.',
            'type' => 'NS',
            'ttl' => 3600,
            'changetype' => 'REPLACE',
            'records' => [
                ['content' => 'ns1.litecontrol.test.', 'disabled' => false],
                ['content' => 'ns2.litecontrol.test.', 'disabled' => false],
            ],
        ]);

    // A records at apex, www, and mail
    expect($byKey['example.com.|A']['records'])->toBe([
        ['content' => '203.0.113.50', 'disabled' => false],
    ])
        ->and($byKey['www.example.com.|A']['records'])->toBe([
            ['content' => '203.0.113.50', 'disabled' => false],
        ])
        ->and($byKey['mail.example.com.|A']['records'])->toBe([
            ['content' => '203.0.113.50', 'disabled' => false],
        ]);

    // AAAA records at apex, www, and mail
    expect($byKey['example.com.|AAAA']['records'])->toBe([
        ['content' => '2001:db8::50', 'disabled' => false],
    ])
        ->and($byKey['www.example.com.|AAAA']['records'])->toBe([
            ['content' => '2001:db8::50', 'disabled' => false],
        ])
        ->and($byKey['mail.example.com.|AAAA']['records'])->toBe([
            ['content' => '2001:db8::50', 'disabled' => false],
        ]);

    // MX record with priority + trailing-dot FQDN
    expect($byKey['example.com.|MX']['records'])->toBe([
        ['content' => '10 mail.example.com.', 'disabled' => false],
    ]);

    // SPF TXT record with expanded IPv4 and preserved quotes
    expect($byKey['example.com.|TXT']['records'])->toBe([
        ['content' => '"v=spf1 a mx ip4:203.0.113.50 ~all"', 'disabled' => false],
    ]);
});

it('automatically skips {{IPV6}} template records when no IPv6 address is supplied', function (): void {
    $variables = new ZoneTemplateVariables(
        domain: 'ipv4-only.org',
        ipv4: '198.51.100.25',
        ipv6: '',
        ns1: 'ns1.hosting.test.',
        ns2: 'ns2.hosting.test.',
    );

    $rrsets = $this->action->handle(DnsZoneTemplate::defaultRecordsPayload(), $variables);
    $types = array_column($rrsets, 'type');

    expect($types)->toContain('NS', 'A', 'MX', 'TXT')
        ->not->toContain('AAAA');
});

it('normalizes relative labels, {{DOMAIN}} in hostnames, unquoted TXT strings, and extracts base domain for glue guidance', function (): void {
    $variables = new ZoneTemplateVariables(
        domain: 'MÜNCHEN.de',
        ipv4: '192.0.2.99',
    );

    $records = [
        new ZoneTemplateRecordData(
            name: '{{DOMAIN}}',
            type: 'A',
            ttl: 300,
            priority: null,
            content: '{{IPV4}}',
        ),
        new ZoneTemplateRecordData(
            name: 'blog.{{DOMAIN}}.',
            type: 'CNAME',
            ttl: 300,
            priority: null,
            content: '@',
        ),
        new ZoneTemplateRecordData(
            name: '_dmarc',
            type: 'TXT',
            ttl: 3600,
            priority: null,
            content: 'v=DMARC1; p=quarantine; rua=mailto:postmaster@{{DOMAIN}}',
        ),
    ];

    $rrsets = $this->action->handle($records, $variables);

    expect($rrsets)->toHaveCount(3)
        ->and($rrsets[0]['name'])->toBe('xn--mnchen-3ya.de.')
        ->and($rrsets[1]['name'])->toBe('blog.xn--mnchen-3ya.de.')
        ->and($rrsets[1]['records'][0]['content'])->toBe('xn--mnchen-3ya.de.')
        ->and($rrsets[2]['name'])->toBe('_dmarc.xn--mnchen-3ya.de.')
        ->and($rrsets[2]['records'][0]['content'])->toBe('"v=DMARC1; p=quarantine; rua=mailto:postmaster@xn--mnchen-3ya.de"');

    $nsEntry = new DefaultNameserverEntry(
        hostname: 'ns1.paneldomain.com',
        ipv4: '203.0.113.10',
        ipv6: '2001:db8::10',
    );

    expect($nsEntry->hostname)->toBe('ns1.paneldomain.com.')
        ->and($nsEntry->baseDomain())->toBe('paneldomain.com.');
});
