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
        'username' => 'pdnsmodeadmin',
        'role' => 'admin',
    ]);

    $this->regularUser = User::factory()->create([
        'username' => 'pdnsmodeuser',
        'role' => 'user',
    ]);

    PdnsSetting::current()->update([
        'host' => '127.0.0.1',
        'port' => 8081,
        'api_key' => str_repeat('a1b2', 16),
        'primary_enabled' => true,
        'secondary_enabled' => true,
        'autosecondary_enabled' => false,
        'allow_axfr_ips' => '198.51.100.0/24',
        'also_notify' => '198.51.100.53',
    ]);
});

it('forbids non-admin users from accessing zone mode and transfer control routes', function (): void {
    $this->actingAs($this->regularUser)
        ->put('/dns/zones/example.com./mode', ['kind' => 'Primary'])
        ->assertForbidden();

    $this->actingAs($this->regularUser)
        ->put('/dns/zones/example.com./notify')
        ->assertForbidden();

    $this->actingAs($this->regularUser)
        ->put('/dns/zones/example.com./axfr-retrieve')
        ->assertForbidden();
});

it('hydrates zone ALLOW-AXFR-FROM, ALSO-NOTIFY metadata, and global daemon capabilities in ZoneRecords page', function (): void {
    Http::fake([
        'http://127.0.0.1:8081/api/v1/servers/localhost/zones/example.com.' => Http::response([
            'id' => 'example.com.',
            'name' => 'example.com.',
            'kind' => 'Primary',
            'serial' => 2026112101,
            'notified_serial' => 2026112101,
            'soa_edit_api' => 'DEFAULT',
            'api_rectify' => true,
            'masters' => [],
            'rrsets' => [],
        ], 200),
        'http://127.0.0.1:8081/api/v1/servers/localhost/zones/example.com./metadata' => Http::response([
            [
                'type' => 'Metadata',
                'kind' => 'ALLOW-AXFR-FROM',
                'metadata' => ['198.51.100.20', '203.0.113.0/24'],
            ],
            [
                'type' => 'Metadata',
                'kind' => 'ALSO-NOTIFY',
                'metadata' => ['198.51.100.25:5300'],
            ],
        ], 200),
    ]);

    $this->actingAs($this->admin)
        ->get('/dns/zones/example.com/records')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dns/ZoneRecords')
            ->where('zone.kind', 'Primary')
            ->where('zone.allow_axfr_from', ['198.51.100.20', '203.0.113.0/24'])
            ->where('zone.also_notify', ['198.51.100.25:5300'])
            ->where('capabilities.primary_enabled', true)
            ->where('capabilities.secondary_enabled', true)
            ->where('capabilities.allow_axfr_ips', '198.51.100.0/24')
            ->where('capabilities.also_notify', '198.51.100.53')
        );
});

it('switches a zone from Native -> Primary -> Secondary -> Primary and enforces confirmation & rectification rules', function (): void {
    $currentKind = 'Native';
    $zonePutPayloads = [];
    $metadataPutCalls = [];
    $rectifyCalls = 0;

    Http::fake(function (Request $request) use (&$currentKind, &$zonePutPayloads, &$metadataPutCalls, &$rectifyCalls) {
        $url = $request->url();

        if ($request->method() === 'GET' && $url === 'http://127.0.0.1:8081/api/v1/servers/localhost/zones/example.com.') {
            return Http::response([
                'id' => 'example.com.',
                'name' => 'example.com.',
                'kind' => $currentKind,
                'serial' => 2026112101,
                'masters' => $currentKind === 'Secondary' ? ['203.0.113.10'] : [],
                'rrsets' => [],
            ], 200);
        }

        if ($request->method() === 'PUT' && $url === 'http://127.0.0.1:8081/api/v1/servers/localhost/zones/example.com.') {
            $data = $request->data();
            $zonePutPayloads[] = $data;
            $currentKind = (string) ($data['kind'] ?? $currentKind);

            return Http::response('', 204);
        }

        if ($request->method() === 'PUT' && str_contains($url, '/zones/example.com./metadata/')) {
            $metadataPutCalls[] = [
                'url' => $url,
                'data' => $request->data(),
            ];

            return Http::response($request->data(), 200);
        }

        if ($request->method() === 'PUT' && $url === 'http://127.0.0.1:8081/api/v1/servers/localhost/zones/example.com./rectify') {
            $rectifyCalls++;

            return Http::response(['result' => 'Rectified'], 200);
        }

        return Http::response([], 200);
    });

    // 1. Switch Native -> Primary with ALLOW-AXFR-FROM and ALSO-NOTIFY metadata
    $this->actingAs($this->admin)
        ->put('/dns/zones/example.com/mode', [
            'kind' => 'Primary',
            'allow_axfr_from' => '198.51.100.20, 203.0.113.0/24',
            'also_notify' => '198.51.100.25, 203.0.113.30:5300',
        ])
        ->assertRedirect(route('dns.zones.records.show', ['zone' => 'example.com.']))
        ->assertSessionHas('success');

    expect($zonePutPayloads[0])->toBe([
        'kind' => 'Primary',
        'masters' => [],
        'soa_edit_api' => 'DEFAULT',
        'api_rectify' => true,
    ])
        ->and($metadataPutCalls[0]['data'])->toBe([
            'kind' => 'ALLOW-AXFR-FROM',
            'metadata' => ['198.51.100.20', '203.0.113.0/24'],
        ])
        ->and($metadataPutCalls[1]['data'])->toBe([
            'kind' => 'ALSO-NOTIFY',
            'metadata' => ['198.51.100.25', '203.0.113.30:5300'],
        ])
        ->and($rectifyCalls)->toBe(0);

    // 2. Attempt Primary -> Secondary WITHOUT confirm_overwrite is rejected before calling PUT /zones
    $this->actingAs($this->admin)
        ->put('/dns/zones/example.com/mode', [
            'kind' => 'Secondary',
            'masters' => '203.0.113.10:53',
            'confirm_overwrite' => false,
        ])
        ->assertSessionHas('error');

    expect($currentKind)->toBe('Primary');

    // 3. Switch Primary -> Secondary WITH confirm_overwrite=true and valid masters
    $this->actingAs($this->admin)
        ->put('/dns/zones/example.com/mode', [
            'kind' => 'Secondary',
            'masters' => '203.0.113.10, 198.51.100.53:53',
            'confirm_overwrite' => true,
        ])
        ->assertRedirect(route('dns.zones.records.show', ['zone' => 'example.com.']))
        ->assertSessionHas('success');

    expect($zonePutPayloads[1])->toBe([
        'kind' => 'Secondary',
        'masters' => ['203.0.113.10', '198.51.100.53:53'],
    ])
        ->and($currentKind)->toBe('Secondary');

    // 4. Switch Secondary -> Native clears masters and triggers PUT /zones/{id}/rectify
    $this->actingAs($this->admin)
        ->put('/dns/zones/example.com/mode', [
            'kind' => 'Native',
        ])
        ->assertRedirect(route('dns.zones.records.show', ['zone' => 'example.com.']))
        ->assertSessionHas('success');

    expect($zonePutPayloads[2])->toBe([
        'kind' => 'Native',
        'masters' => [],
        'soa_edit_api' => 'DEFAULT',
        'api_rectify' => true,
    ])
        ->and($rectifyCalls)->toBe(1);

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $this->admin->id,
        'action' => 'pdns_update_zone_mode',
        'command' => 'dns.zones.mode.update',
        'status' => 'success',
    ]);
});

it('executes PUT /notify on Primary zones and PUT /axfr-retrieve on Secondary zones cleanly', function (): void {
    $notifyCalled = false;
    $axfrCalled = false;

    Http::fake(function (Request $request) use (&$notifyCalled, &$axfrCalled) {
        $url = $request->url();

        if ($request->method() === 'GET' && $url === 'http://127.0.0.1:8081/api/v1/servers/localhost/zones/primary.example.com.') {
            return Http::response([
                'id' => 'primary.example.com.',
                'name' => 'primary.example.com.',
                'kind' => 'Primary',
                'serial' => 2026112101,
            ], 200);
        }

        if ($request->method() === 'PUT' && $url === 'http://127.0.0.1:8081/api/v1/servers/localhost/zones/primary.example.com./notify') {
            $notifyCalled = true;

            return Http::response(['result' => 'Notification queued'], 200);
        }

        if ($request->method() === 'GET' && $url === 'http://127.0.0.1:8081/api/v1/servers/localhost/zones/secondary.example.com.') {
            return Http::response([
                'id' => 'secondary.example.com.',
                'name' => 'secondary.example.com.',
                'kind' => 'Secondary',
                'serial' => 2026112101,
                'masters' => ['203.0.113.10'],
            ], 200);
        }

        if ($request->method() === 'PUT' && $url === 'http://127.0.0.1:8081/api/v1/servers/localhost/zones/secondary.example.com./axfr-retrieve') {
            $axfrCalled = true;

            return Http::response(['result' => 'Added retrieval request for domain'], 200);
        }

        return Http::response([], 200);
    });

    // 1. Trigger Notify on Primary zone
    $this->actingAs($this->admin)
        ->put('/dns/zones/primary.example.com/notify')
        ->assertRedirect(route('dns.zones.records.show', ['zone' => 'primary.example.com.']))
        ->assertSessionHas('success');

    expect($notifyCalled)->toBeTrue();

    // 2. Reject Notify on Secondary zone
    $this->actingAs($this->admin)
        ->put('/dns/zones/secondary.example.com/notify')
        ->assertSessionHas('error');

    // 3. Trigger AXFR Retrieve on Secondary zone
    $this->actingAs($this->admin)
        ->put('/dns/zones/secondary.example.com/axfr-retrieve')
        ->assertRedirect(route('dns.zones.records.show', ['zone' => 'secondary.example.com.']))
        ->assertSessionHas('success');

    expect($axfrCalled)->toBeTrue();

    // 4. Reject AXFR Retrieve on Primary zone
    $this->actingAs($this->admin)
        ->put('/dns/zones/primary.example.com/axfr-retrieve')
        ->assertSessionHas('error');

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $this->admin->id,
        'action' => 'pdns_trigger_notify',
        'command' => 'dns.zones.notify',
        'status' => 'success',
    ]);

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $this->admin->id,
        'action' => 'pdns_trigger_axfr_retrieve',
        'command' => 'dns.zones.axfr-retrieve',
        'status' => 'success',
    ]);
});
