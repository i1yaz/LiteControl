<?php

declare(strict_types=1);

use App\Domains\Dns\Models\PdnsSetting;
use App\Domains\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = User::factory()->create([
        'username' => 'pdnsrecordadmin',
        'role' => 'admin',
    ]);

    $this->regularUser = User::factory()->create([
        'username' => 'pdnsrecorduser',
        'role' => 'user',
    ]);

    PdnsSetting::current()->update([
        'host' => '127.0.0.1',
        'port' => 8081,
        'api_key' => str_repeat('c3d4', 16),
        'primary_enabled' => true,
        'secondary_enabled' => true,
    ]);
});

it('forbids non-admin users from accessing DNS record editor and SOA routes', function (): void {
    $this->actingAs($this->regularUser)->get('/dns/zones/example.com./records')->assertForbidden();
    $this->actingAs($this->regularUser)->patch('/dns/zones/example.com./records', [])->assertForbidden();
    $this->actingAs($this->regularUser)->put('/dns/zones/example.com./soa', [])->assertForbidden();
});

it('renders the ZoneRecords page with parsed RRSets, structured fields, and SOA settings', function (): void {
    Http::fake([
        'http://127.0.0.1:8081/api/v1/servers/localhost/zones/example.com.' => Http::response([
            'id' => 'example.com.',
            'name' => 'example.com.',
            'kind' => 'Primary',
            'serial' => 2026112101,
            'dnssec' => false,
            'soa_edit_api' => 'DEFAULT',
            'api_rectify' => true,
            'rrsets' => [
                [
                    'name' => 'example.com.',
                    'type' => 'SOA',
                    'ttl' => 3600,
                    'records' => [
                        [
                            'content' => 'ns1.example.com. dns\\.admin.example.com. 2026112101 10800 3600 604800 3600',
                            'disabled' => false,
                        ],
                    ],
                    'comments' => [],
                ],
                [
                    'name' => 'example.com.',
                    'type' => 'MX',
                    'ttl' => 3600,
                    'records' => [
                        [
                            'content' => '10 mail.example.com.',
                            'disabled' => false,
                        ],
                    ],
                    'comments' => [
                        ['content' => 'Primary MX', 'account' => 'admin'],
                    ],
                ],
                [
                    'name' => '_sip._tcp.example.com.',
                    'type' => 'SRV',
                    'ttl' => 900,
                    'records' => [
                        [
                            'content' => '10 20 5060 sip.example.com.',
                            'disabled' => false,
                        ],
                    ],
                    'comments' => [],
                ],
                [
                    'name' => 'example.com.',
                    'type' => 'CAA',
                    'ttl' => 3600,
                    'records' => [
                        [
                            'content' => '0 issue "letsencrypt.org"',
                            'disabled' => false,
                        ],
                    ],
                    'comments' => [],
                ],
            ],
        ], 200),
    ]);

    $response = $this->actingAs($this->admin)->get('/dns/zones/example.com/records');

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dns/ZoneRecords')
            ->where('zone.name', 'example.com.')
            ->where('zone.kind', 'Primary')
            ->where('zone.serial', 2026112101)
            ->where('zone.soa.primary_ns', 'ns1.example.com.')
            ->where('zone.soa.hostmaster_email', 'dns.admin@example.com')
            ->where('zone.soa.refresh', 10800)
            ->where('isReadOnly', false)
            ->has('zone.rrsets', 4)
            ->where('zone.rrsets.0.type', 'SOA')
            ->where('zone.rrsets.0.relative_name', '@')
        );
});

it('performs CRUD across all supported record types (A, AAAA, CNAME, MX, TXT, SRV, NS, PTR, CAA) via atomic batch PATCH', function (): void {
    $patchedPayloads = [];

    Http::fake(function (Request $request) use (&$patchedPayloads) {
        if ($request->method() === 'GET' && str_contains($request->url(), '/zones/example.com.')) {
            return Http::response([
                'id' => 'example.com.',
                'name' => 'example.com.',
                'kind' => 'Primary',
                'serial' => 2026112101,
                'rrsets' => [
                    [
                        'name' => 'example.com.',
                        'type' => 'SOA',
                        'ttl' => 3600,
                        'records' => [
                            ['content' => 'ns1.example.com. hostmaster.example.com. 2026112101 10800 3600 604800 3600', 'disabled' => false],
                        ],
                    ],
                    [
                        'name' => 'old.example.com.',
                        'type' => 'A',
                        'ttl' => 3600,
                        'records' => [
                            ['content' => '192.0.2.99', 'disabled' => false],
                        ],
                    ],
                ],
            ], 200);
        }

        if ($request->method() === 'PATCH' && str_contains($request->url(), '/zones/example.com.')) {
            $patchedPayloads[] = $request->data();

            return Http::response('', 204);
        }

        return Http::response([], 200);
    });

    $longDkim = 'v=DKIM1; k=rsa; p='.str_repeat('MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA', 7);

    $response = $this->actingAs($this->admin)->patch('/dns/zones/example.com/records', [
        'rrsets' => [
            // 1. A record with comment and disabled fallback node
            [
                'name' => 'www',
                'type' => 'A',
                'ttl' => 300,
                'changetype' => 'REPLACE',
                'records' => [
                    ['content' => '203.0.113.10', 'disabled' => false],
                    ['content' => '203.0.113.11', 'disabled' => true],
                ],
                'comments' => [
                    ['content' => 'Web cluster', 'account' => 'admin'],
                ],
            ],
            // 2. AAAA record
            [
                'name' => 'www',
                'type' => 'AAAA',
                'ttl' => 300,
                'changetype' => 'REPLACE',
                'records' => [
                    ['content' => '2001:0DB8:0000:0000:0000:0000:0000:0010', 'disabled' => false],
                ],
            ],
            // 3. CNAME record
            [
                'name' => 'cdn',
                'type' => 'CNAME',
                'ttl' => 900,
                'changetype' => 'REPLACE',
                'records' => [
                    ['content' => 'edge.provider.net', 'disabled' => false],
                ],
            ],
            // 4. MX record (structured priority + target)
            [
                'name' => '@',
                'type' => 'MX',
                'ttl' => 3600,
                'changetype' => 'REPLACE',
                'records' => [
                    ['priority' => 10, 'target' => 'mail.example.com', 'disabled' => false],
                ],
            ],
            // 5. TXT record (>255-byte DKIM auto-chunked)
            [
                'name' => 'default._domainkey',
                'type' => 'TXT',
                'ttl' => 3600,
                'changetype' => 'REPLACE',
                'records' => [
                    ['content' => $longDkim, 'disabled' => false],
                ],
            ],
            // 6. SRV record (structured priority, weight, port, target)
            [
                'name' => '_sip._tcp',
                'type' => 'SRV',
                'ttl' => 900,
                'changetype' => 'REPLACE',
                'records' => [
                    ['priority' => 10, 'weight' => 60, 'port' => 5060, 'target' => 'sip.example.com', 'disabled' => false],
                ],
            ],
            // 7. NS record for delegated subzone
            [
                'name' => 'delegated',
                'type' => 'NS',
                'ttl' => 86400,
                'changetype' => 'REPLACE',
                'records' => [
                    ['content' => 'ns1.other.net', 'disabled' => false],
                ],
            ],
            // 8. PTR record
            [
                'name' => 'ptr-host',
                'type' => 'PTR',
                'ttl' => 3600,
                'changetype' => 'REPLACE',
                'records' => [
                    ['content' => 'host.example.com', 'disabled' => false],
                ],
            ],
            // 9. CAA record (structured flags, tag, value)
            [
                'name' => '@',
                'type' => 'CAA',
                'ttl' => 3600,
                'changetype' => 'REPLACE',
                'records' => [
                    ['flags' => 0, 'tag' => 'issue', 'value' => 'letsencrypt.org', 'disabled' => false],
                ],
            ],
            // 10. EXTEND single record quick-add
            [
                'name' => '_acme-challenge',
                'type' => 'TXT',
                'ttl' => 60,
                'changetype' => 'EXTEND',
                'records' => [
                    ['content' => 'acme-token-123', 'disabled' => false],
                ],
            ],
            // 11. DELETE old RRSet (omits ttl)
            [
                'name' => 'old',
                'type' => 'A',
                'changetype' => 'DELETE',
            ],
        ],
    ]);

    $response->assertRedirect(route('dns.zones.records.show', ['zone' => 'example.com.']))
        ->assertSessionHas('success');

    expect($patchedPayloads)->toHaveCount(1);
    $sentRrsets = $patchedPayloads[0]['rrsets'];
    expect($sentRrsets)->toHaveCount(11)
        ->and($sentRrsets[0]['name'])->toBe('www.example.com.')
        ->and($sentRrsets[0]['records'][1]['disabled'])->toBeTrue()
        ->and($sentRrsets[1]['records'][0]['content'])->toBe('2001:db8::10')
        ->and($sentRrsets[2]['records'][0]['content'])->toBe('edge.provider.net.')
        ->and($sentRrsets[3]['records'][0]['content'])->toBe('10 mail.example.com.')
        ->and($sentRrsets[5]['records'][0]['content'])->toBe('10 60 5060 sip.example.com.')
        ->and($sentRrsets[8]['records'][0]['content'])->toBe('0 issue "letsencrypt.org"')
        ->and($sentRrsets[9]['changetype'])->toBe('EXTEND')
        ->and($sentRrsets[9]['comments'])->toBe([])
        ->and($sentRrsets[10]['changetype'])->toBe('DELETE')
        ->and(array_key_exists('ttl', $sentRrsets[10]))->toBeFalse();

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $this->admin->id,
        'action' => 'pdns_update_rrsets',
        'command' => 'dns.zones.records.patch',
        'status' => 'success',
    ]);
});

it('updates zone SOA settings, converting @ email to RNAME and preserving serial unless manual override is enabled', function (): void {
    $patchedPayloads = [];

    Http::fake(function (Request $request) use (&$patchedPayloads) {
        if ($request->method() === 'GET' && str_contains($request->url(), '/zones/example.com.')) {
            return Http::response([
                'id' => 'example.com.',
                'name' => 'example.com.',
                'kind' => 'Primary',
                'serial' => 2026112105,
                'rrsets' => [
                    [
                        'name' => 'example.com.',
                        'type' => 'SOA',
                        'ttl' => 3600,
                        'records' => [
                            ['content' => 'ns1.example.com. hostmaster.example.com. 2026112105 10800 3600 604800 3600', 'disabled' => false],
                        ],
                    ],
                ],
            ], 200);
        }

        if ($request->method() === 'PATCH' && str_contains($request->url(), '/zones/example.com.')) {
            $patchedPayloads[] = $request->data();

            return Http::response('', 204);
        }

        return Http::response([], 200);
    });

    // 1. Default SOA update preserves current serial (for SOA-EDIT-API auto-increment) and converts hostmaster@example.com -> hostmaster.example.com.
    $this->actingAs($this->admin)->put('/dns/zones/example.com/soa', [
        'primary_ns' => 'ns1.panel.example.com',
        'hostmaster' => 'noc.ops@example.com',
        'ttl' => 7200,
        'refresh' => 14400,
        'retry' => 1800,
        'expire' => 1209600,
        'minimum' => 900,
        'manual_serial_override' => false,
        'serial' => 9999999999, // Ignored when manual_serial_override is false
        'comment' => 'Updated SOA timers',
    ])->assertRedirect(route('dns.zones.records.show', ['zone' => 'example.com.']))
        ->assertSessionHas('success');

    expect($patchedPayloads)->toHaveCount(1)
        ->and($patchedPayloads[0]['rrsets'][0])->toMatchArray([
            'name' => 'example.com.',
            'type' => 'SOA',
            'ttl' => 7200,
            'changetype' => 'REPLACE',
            'records' => [
                [
                    'content' => 'ns1.panel.example.com. noc\\.ops.example.com. 2026112105 14400 1800 1209600 900',
                    'disabled' => false,
                ],
            ],
        ]);

    // 2. Manual serial override uses explicit serial
    $this->actingAs($this->admin)->put('/dns/zones/example.com/soa', [
        'primary_ns' => 'ns1.panel.example.com.',
        'hostmaster' => 'hostmaster@example.com',
        'ttl' => 3600,
        'refresh' => 10800,
        'retry' => 3600,
        'expire' => 604800,
        'minimum' => 3600,
        'manual_serial_override' => true,
        'serial' => 2026123199,
    ])->assertRedirect(route('dns.zones.records.show', ['zone' => 'example.com.']))
        ->assertSessionHas('success');

    expect($patchedPayloads)->toHaveCount(2)
        ->and($patchedPayloads[1]['rrsets'][0]['records'][0]['content'])
        ->toBe('ns1.panel.example.com. hostmaster.example.com. 2026123199 10800 3600 604800 3600');

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $this->admin->id,
        'action' => 'pdns_update_soa',
        'command' => 'dns.zones.soa.update',
        'status' => 'success',
    ]);
});

it('blocks record mutations and SOA edits on Secondary (read-only) zones', function (): void {
    Http::fake([
        'http://127.0.0.1:8081/api/v1/servers/localhost/zones/replica.example.com.' => Http::response([
            'id' => 'replica.example.com.',
            'name' => 'replica.example.com.',
            'kind' => 'Secondary',
            'serial' => 2026112101,
            'masters' => ['198.51.100.10'],
            'rrsets' => [],
        ], 200),
    ]);

    $this->actingAs($this->admin)->patch('/dns/zones/replica.example.com./records', [
        'rrsets' => [
            [
                'name' => 'www',
                'type' => 'A',
                'ttl' => 3600,
                'changetype' => 'REPLACE',
                'records' => [['content' => '203.0.113.10']],
            ],
        ],
    ])->assertSessionHas('error');

    $this->actingAs($this->admin)->put('/dns/zones/replica.example.com./soa', [
        'primary_ns' => 'ns1.replica.example.com.',
        'hostmaster' => 'hostmaster@replica.example.com',
        'ttl' => 3600,
        'refresh' => 10800,
        'retry' => 3600,
        'expire' => 604800,
        'minimum' => 3600,
    ])->assertSessionHas('error');

    Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
});
