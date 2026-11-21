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
        'username' => 'pdnszoneadmin',
        'role' => 'admin',
    ]);

    $this->regularUser = User::factory()->create([
        'username' => 'pdnszoneuser',
        'role' => 'user',
    ]);

    PdnsSetting::current()->update([
        'host' => '127.0.0.1',
        'port' => 8081,
        'api_key' => str_repeat('a1b2', 16),
        'primary_enabled' => true,
        'secondary_enabled' => true,
        'default_nameservers' => [
            ['hostname' => 'ns1.litecontrol.test.', 'ipv4' => '203.0.113.10'],
            ['hostname' => 'ns2.litecontrol.test.', 'ipv4' => '203.0.113.11'],
        ],
    ]);
});

it('forbids non-admin users from accessing DNS zone manager routes', function (): void {
    $this->actingAs($this->regularUser)->get('/dns/zones')->assertForbidden();
    $this->actingAs($this->regularUser)->post('/dns/zones', [])->assertForbidden();
    $this->actingAs($this->regularUser)->get('/dns/zones/example.com./export')->assertForbidden();
    $this->actingAs($this->regularUser)->delete('/dns/zones/example.com.', [])->assertForbidden();
});

it('lists forward and reverse zones and executes cross-zone search via /search-data', function (): void {
    Http::fake([
        'http://127.0.0.1:8081/api/v1/servers/localhost/zones' => Http::response([
            [
                'id' => 'example.com.',
                'name' => 'example.com.',
                'kind' => 'Primary',
                'serial' => 2026112101,
                'dnssec' => true,
                'soa_edit_api' => 'DEFAULT',
                'api_rectify' => true,
            ],
            [
                'id' => '2.0.192.in-addr.arpa.',
                'name' => '2.0.192.in-addr.arpa.',
                'kind' => 'Native',
                'serial' => 2026112102,
                'dnssec' => false,
            ],
            [
                'id' => 'd.c.b.a.8.b.d.0.1.0.0.2.ip6.arpa.',
                'name' => 'd.c.b.a.8.b.d.0.1.0.0.2.ip6.arpa.',
                'kind' => 'Secondary',
                'serial' => 2026112103,
                'dnssec' => false,
                'masters' => ['198.51.100.10'],
            ],
        ], 200),
        'http://127.0.0.1:8081/api/v1/servers/localhost/search-data*' => Http::response([
            [
                'object_type' => 'record',
                'zone_id' => 'example.com.',
                'zone' => 'example.com.',
                'name' => 'mail.example.com.',
                'type' => 'A',
                'ttl' => 3600,
                'content' => '192.0.2.25',
                'disabled' => false,
            ],
        ], 200),
    ]);

    $response = $this->actingAs($this->admin)->get('/dns/zones?q=192.0.2.25');

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dns/Zones')
            ->has('zones', 3)
            ->where('searchQuery', '192.0.2.25')
            ->has('searchResults', 1)
            ->where('searchResults.0.name', 'mail.example.com.')
            ->where('searchResults.0.content', '192.0.2.25')
            ->where('apiError', null)
        );

    Http::assertSent(fn (Request $r) => $r->method() === 'GET'
        && str_starts_with($r->url(), 'http://127.0.0.1:8081/api/v1/servers/localhost/search-data')
        && $r['q'] === '*192.0.2.25*'
        && (int) $r['max'] === 100
    );
});

it('creates a forward zone, an IPv4 /24 reverse zone, and an IPv6 /48 reverse zone, exports BIND file, and deletes all three cleanly', function (): void {
    $createdPayloads = [];
    $deletedZones = [];

    Http::fake(function (Request $request) use (&$createdPayloads, &$deletedZones) {
        if ($request->method() === 'POST' && $request->url() === 'http://127.0.0.1:8081/api/v1/servers/localhost/zones') {
            $data = $request->data();
            $createdPayloads[] = $data;

            return Http::response([
                'id' => $data['name'],
                'name' => $data['name'],
                'kind' => $data['kind'],
                'serial' => 2026112101,
            ], 201);
        }

        if ($request->method() === 'GET' && str_ends_with($request->url(), '/export')) {
            return Http::response([
                'zone' => "example.com.\t3600\tIN\tSOA\tns1.litecontrol.test. hostmaster.example.com. 2026112101 10800 3600 604800 3600\nexample.com.\t3600\tIN\tNS\tns1.litecontrol.test.\n",
            ], 200);
        }

        if ($request->method() === 'DELETE' && str_contains($request->url(), '/zones/')) {
            $pathParts = explode('/zones/', $request->url());
            $deletedZones[] = rawurldecode($pathParts[1] ?? '');

            return Http::response('', 204);
        }

        return Http::response([], 200);
    });

    // 1. Create Forward Zone (example.com -> example.com.)
    $this->actingAs($this->admin)->post('/dns/zones', [
        'zone_type' => 'forward',
        'name' => 'example.com',
        'kind' => 'Primary',
        'nameservers' => 'ns1.litecontrol.test, ns2.litecontrol.test.',
    ])->assertRedirect(route('dns.zones.index'))
        ->assertSessionHas('success');

    // 2. Create IPv4 /24 Reverse Zone (192.0.2.0/24 -> 2.0.192.in-addr.arpa.)
    $this->actingAs($this->admin)->post('/dns/zones', [
        'zone_type' => 'reverse_ipv4',
        'reverse_prefix' => '192.0.2.0/24',
        'kind' => 'Native',
        'nameservers' => '',
    ])->assertRedirect(route('dns.zones.index'))
        ->assertSessionHas('success');

    // 3. Create IPv6 /48 Reverse Zone (2001:db8:abcd::/48 -> d.c.b.a.8.b.d.0.1.0.0.2.ip6.arpa.)
    $this->actingAs($this->admin)->post('/dns/zones', [
        'zone_type' => 'reverse_ipv6',
        'reverse_prefix' => '2001:db8:abcd::/48',
        'kind' => 'Native',
        'nameservers' => '',
    ])->assertRedirect(route('dns.zones.index'))
        ->assertSessionHas('success');

    expect($createdPayloads)->toHaveCount(3)
        ->and($createdPayloads[0])->toMatchArray([
            'name' => 'example.com.',
            'kind' => 'Primary',
            'nameservers' => ['ns1.litecontrol.test.', 'ns2.litecontrol.test.'],
            'soa_edit_api' => 'DEFAULT',
            'api_rectify' => true,
        ])
        ->and($createdPayloads[1])->toMatchArray([
            'name' => '2.0.192.in-addr.arpa.',
            'kind' => 'Native',
            'nameservers' => ['ns1.litecontrol.test.', 'ns2.litecontrol.test.'],
            'soa_edit_api' => 'DEFAULT',
            'api_rectify' => true,
        ])
        ->and($createdPayloads[2])->toMatchArray([
            'name' => 'd.c.b.a.8.b.d.0.1.0.0.2.ip6.arpa.',
            'kind' => 'Native',
            'nameservers' => ['ns1.litecontrol.test.', 'ns2.litecontrol.test.'],
            'soa_edit_api' => 'DEFAULT',
            'api_rectify' => true,
        ]);

    // 4. Export BIND .zone file
    $exportResponse = $this->actingAs($this->admin)->get('/dns/zones/example.com./export');
    $exportResponse->assertOk()
        ->assertDownload('example.com.zone');
    expect($exportResponse->streamedContent())->toContain('IN	SOA	ns1.litecontrol.test.');

    // 5. Reject deletion when confirmation name does not match
    $this->actingAs($this->admin)->delete('/dns/zones/example.com.', [
        'confirm_name' => 'wrong-domain.com',
    ])->assertSessionHasErrors(['confirm_name']);

    expect($deletedZones)->toBeEmpty();

    // 6. Delete all three zones cleanly
    $this->actingAs($this->admin)->delete('/dns/zones/example.com.', [
        'confirm_name' => 'example.com',
    ])->assertRedirect(route('dns.zones.index'))
        ->assertSessionHas('success');

    $this->actingAs($this->admin)->delete('/dns/zones/2.0.192.in-addr.arpa.', [
        'confirm_name' => '2.0.192.in-addr.arpa.',
    ])->assertRedirect(route('dns.zones.index'))
        ->assertSessionHas('success');

    $this->actingAs($this->admin)->delete('/dns/zones/d.c.b.a.8.b.d.0.1.0.0.2.ip6.arpa.', [
        'confirm_name' => 'd.c.b.a.8.b.d.0.1.0.0.2.ip6.arpa',
    ])->assertRedirect(route('dns.zones.index'))
        ->assertSessionHas('success');

    expect($deletedZones)->toBe([
        'example.com.',
        '2.0.192.in-addr.arpa.',
        'd.c.b.a.8.b.d.0.1.0.0.2.ip6.arpa.',
    ]);

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $this->admin->id,
        'action' => 'pdns_create_zone',
        'command' => 'dns.zones.store',
        'status' => 'success',
    ]);

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $this->admin->id,
        'action' => 'pdns_export_zone',
        'command' => 'dns.zones.export',
        'status' => 'success',
    ]);

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $this->admin->id,
        'action' => 'pdns_delete_zone',
        'command' => 'dns.zones.destroy',
        'status' => 'success',
    ]);
});

it('requires upstream primary master IPs when creating a Secondary zone', function (): void {
    Http::fake();

    $this->actingAs($this->admin)->post('/dns/zones', [
        'zone_type' => 'forward',
        'name' => 'secondary-example.com',
        'kind' => 'Secondary',
        'masters' => '',
    ])->assertSessionHasErrors(['masters']);

    Http::assertNothingSent();
});
