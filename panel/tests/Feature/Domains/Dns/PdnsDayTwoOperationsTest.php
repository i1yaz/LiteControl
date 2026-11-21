<?php

declare(strict_types=1);

use App\Domains\Dns\Models\PdnsSetting;
use App\Domains\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = User::factory()->create([
        'username' => 'pdnsopsadmin',
        'role' => 'admin',
    ]);

    $this->regularUser = User::factory()->create([
        'username' => 'pdnsopsuser',
        'role' => 'user',
    ]);

    $this->secretApiKey = 'f0e1d2c3b4a5968778695a4b3c2d1e0ff0e1d2c3b4a5968778695a4b3c2d1e0f';

    PdnsSetting::current()->update([
        'host' => '127.0.0.1',
        'port' => 8081,
        'api_key' => $this->secretApiKey,
        'primary_enabled' => true,
        'secondary_enabled' => true,
        'autosecondary_enabled' => true,
    ]);
});

it('forbids non-admin users from accessing autoprimaries, zone rectification, and cache flush routes', function (): void {
    $this->actingAs($this->regularUser)
        ->post('/dns/autoprimaries', [
            'ip' => '203.0.113.10',
            'nameserver' => 'ns1.example.com.',
        ])
        ->assertForbidden();

    $this->actingAs($this->regularUser)
        ->delete('/dns/autoprimaries/203.0.113.10/ns1.example.com.')
        ->assertForbidden();

    $this->actingAs($this->regularUser)
        ->put('/dns/zones/example.com./rectify')
        ->assertForbidden();

    $this->actingAs($this->regularUser)
        ->put('/dns/zones/example.com./cache/flush')
        ->assertForbidden();

    $this->actingAs($this->regularUser)
        ->put('/dns/settings/cache/flush')
        ->assertForbidden();
});

it('lists, adds, and deletes autoprimaries (supermasters) and surfaces capability flags on Zones and Settings pages', function (): void {
    $autoprimariesStore = [
        [
            'ip' => '198.51.100.10',
            'nameserver' => 'ns1.upstream.example.com.',
            'account' => 'upstream-core',
        ],
    ];

    Http::fake(function (Request $request) use (&$autoprimariesStore) {
        $url = $request->url();

        if ($request->method() === 'GET' && $url === 'http://127.0.0.1:8081/api/v1/servers/localhost') {
            return Http::response([
                'type' => 'Server',
                'id' => 'localhost',
                'daemon_type' => 'authoritative',
                'version' => '4.9.1',
            ], 200);
        }

        if ($request->method() === 'GET' && $url === 'http://127.0.0.1:8081/api/v1/servers/localhost/config') {
            return Http::response([
                ['type' => 'ConfigSetting', 'name' => 'launch', 'value' => 'gmysql'],
            ], 200);
        }

        if ($request->method() === 'GET' && $url === 'http://127.0.0.1:8081/api/v1/servers/localhost/zones') {
            return Http::response([], 200);
        }

        if ($request->method() === 'GET' && $url === 'http://127.0.0.1:8081/api/v1/servers/localhost/autoprimaries') {
            return Http::response(array_values($autoprimariesStore), 200);
        }

        if ($request->method() === 'POST' && $url === 'http://127.0.0.1:8081/api/v1/servers/localhost/autoprimaries') {
            $data = $request->data();
            $autoprimariesStore[] = [
                'ip' => $data['ip'],
                'nameserver' => $data['nameserver'],
                'account' => $data['account'] ?? '',
            ];

            return Http::response($data, 201);
        }

        if ($request->method() === 'DELETE' && str_starts_with($url, 'http://127.0.0.1:8081/api/v1/servers/localhost/autoprimaries/')) {
            $suffix = substr($url, strlen('http://127.0.0.1:8081/api/v1/servers/localhost/autoprimaries/'));
            [$encIp, $encNs] = explode('/', $suffix, 2);
            $targetIp = rawurldecode($encIp);
            $targetNs = rawurldecode($encNs);

            $autoprimariesStore = array_values(array_filter(
                $autoprimariesStore,
                static fn (array $row): bool => ! ($row['ip'] === $targetIp && $row['nameserver'] === $targetNs)
            ));

            return Http::response('', 204);
        }

        return Http::response([], 200);
    });

    // 1. Add a new autoprimary (normalizes nameserver to trailing-dot lowercase FQDN)
    $this->actingAs($this->admin)
        ->post('/dns/autoprimaries', [
            'ip' => '203.0.113.53',
            'nameserver' => 'NS2.PrimaryNode.Example.COM',
            'account' => 'dc2-cluster',
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Autoprimary 203.0.113.53 (ns2.primarynode.example.com.) registered.');

    // 2. Verify Zones page lists both autoprimaries and capabilities
    $this->actingAs($this->admin)
        ->get('/dns/zones')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dns/Zones')
            ->has('autoprimaries', 2)
            ->where('autoprimaries.0.ip', '198.51.100.10')
            ->where('autoprimaries.1.ip', '203.0.113.53')
            ->where('autoprimaries.1.nameserver', 'ns2.primarynode.example.com.')
            ->where('autoprimaries.1.account', 'dc2-cluster')
            ->where('capabilities.autosecondary_enabled', true)
            ->where('capabilities.secondary_enabled', true)
        );

    // 3. Verify Settings page also lists autoprimaries
    $this->actingAs($this->admin)
        ->get('/dns/settings')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dns/Settings')
            ->has('autoprimaries', 2)
            ->where('settings.autosecondary_enabled', true)
        );

    // 4. Delete the newly added autoprimary
    $this->actingAs($this->admin)
        ->delete('/dns/autoprimaries/203.0.113.53/ns2.primarynode.example.com.')
        ->assertRedirect()
        ->assertSessionHas('success', 'Autoprimary 203.0.113.53 (ns2.primarynode.example.com.) removed.');

    expect($autoprimariesStore)->toHaveCount(1)
        ->and($autoprimariesStore[0]['ip'])->toBe('198.51.100.10');

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $this->admin->id,
        'action' => 'pdns_create_autoprimary',
        'command' => 'dns.autoprimaries.store',
        'status' => 'success',
    ]);

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $this->admin->id,
        'action' => 'pdns_delete_autoprimary',
        'command' => 'dns.autoprimaries.destroy',
        'status' => 'success',
    ]);
});

it('triggers zone rectification, per-zone cache flush, and global cache flush with structured secret-free audit logs', function (): void {
    $rectifyCalled = false;
    $flushedDomains = [];

    Log::spy();

    Http::fake(function (Request $request) use (&$rectifyCalled, &$flushedDomains) {
        $url = $request->url();

        if ($request->method() === 'GET' && $url === 'http://127.0.0.1:8081/api/v1/servers/localhost/zones/example.com.') {
            return Http::response([
                'id' => 'example.com.',
                'name' => 'example.com.',
                'kind' => 'Primary',
                'serial' => 2026112101,
            ], 200);
        }

        if ($request->method() === 'PUT' && $url === 'http://127.0.0.1:8081/api/v1/servers/localhost/zones/example.com./rectify') {
            $rectifyCalled = true;

            return Http::response([
                'result' => 'Rectified',
            ], 200);
        }

        if ($request->method() === 'PUT' && str_starts_with($url, 'http://127.0.0.1:8081/api/v1/servers/localhost/cache/flush')) {
            $parsedQuery = [];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $parsedQuery);
            $domain = (string) ($parsedQuery['domain'] ?? '');
            $flushedDomains[] = $domain;

            return Http::response([
                'count' => $domain === '.' ? 128 : 19,
                'result' => 'Flushed cache.',
            ], 200);
        }

        return Http::response([], 200);
    });

    // 1. Trigger Zone Rectification
    $this->actingAs($this->admin)
        ->put('/dns/zones/example.com/rectify')
        ->assertRedirect(route('dns.zones.records.show', ['zone' => 'example.com.']))
        ->assertSessionHas('success', 'Zone example.com. rectified (Rectified).');

    expect($rectifyCalled)->toBeTrue();

    // 2. Trigger Per-Zone Cache Flush
    $this->actingAs($this->admin)
        ->put('/dns/zones/example.com/cache/flush')
        ->assertRedirect(route('dns.zones.records.show', ['zone' => 'example.com.']))
        ->assertSessionHas('success', 'PowerDNS cache flushed for example.com. (19 entries cleared).');

    // 3. Trigger Global Cache Flush from DNS Settings
    $this->actingAs($this->admin)
        ->put('/dns/settings/cache/flush', ['domain' => '.'])
        ->assertRedirect()
        ->assertSessionHas('success', 'PowerDNS cache flushed for all zones (.) (128 entries cleared).');

    expect($flushedDomains)->toBe(['example.com.', '.']);

    // 4. Verify structured logs include actor, action, zone, mode, status and never leak the API key
    $secret = $this->secretApiKey;

    Log::shouldHaveReceived('info')
        ->withArgs(function (string $message, array $context) use ($secret): bool {
            $serialized = json_encode($context, JSON_THROW_ON_ERROR);

            return $message === 'PowerDNS zone rectified'
                && ($context['actor'] ?? null) === 'pdnsopsadmin'
                && ($context['action'] ?? null) === 'pdns_rectify_zone'
                && ($context['zone'] ?? null) === 'example.com.'
                && ($context['mode'] ?? null) === 'Primary'
                && ($context['status'] ?? null) === 'success'
                && ! str_contains($serialized, $secret);
        });

    Log::shouldHaveReceived('info')
        ->withArgs(function (string $message, array $context) use ($secret): bool {
            $serialized = json_encode($context, JSON_THROW_ON_ERROR);

            return $message === 'PowerDNS authoritative cache flushed'
                && ($context['actor'] ?? null) === 'pdnsopsadmin'
                && ($context['action'] ?? null) === 'pdns_flush_cache'
                && ($context['zone'] ?? null) === 'example.com.'
                && ($context['mode'] ?? null) === 'zone'
                && ($context['status'] ?? null) === 'success'
                && ! str_contains($serialized, $secret);
        });

    Log::shouldHaveReceived('info')
        ->withArgs(function (string $message, array $context) use ($secret): bool {
            $serialized = json_encode($context, JSON_THROW_ON_ERROR);

            return $message === 'PowerDNS authoritative cache flushed'
                && ($context['actor'] ?? null) === 'pdnsopsadmin'
                && ($context['action'] ?? null) === 'pdns_flush_cache'
                && ($context['zone'] ?? null) === '.'
                && ($context['mode'] ?? null) === 'global'
                && ($context['status'] ?? null) === 'success'
                && ! str_contains($serialized, $secret);
        });

    // 5. Verify AuditLog database rows contain zero leaked secrets
    $auditRows = DB::table('audit_logs')->get();
    expect($auditRows)->toHaveCount(3);

    foreach ($auditRows as $row) {
        expect((string) $row->details)->not->toContain($secret);
    }
});
