<?php

declare(strict_types=1);

use App\Domains\Dns\Models\DnsZoneTemplate;
use App\Domains\Dns\Models\PdnsSetting;
use App\Domains\Identity\Models\User;
use Database\Seeders\DnsZoneTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = User::factory()->create([
        'username' => 'pdnstpladmin',
        'role' => 'admin',
    ]);

    $this->regularUser = User::factory()->create([
        'username' => 'pdnstpluser',
        'role' => 'user',
    ]);

    PdnsSetting::current()->update([
        'host' => '127.0.0.1',
        'port' => 8081,
        'api_key' => str_repeat('c3d4', 16),
        'primary_enabled' => true,
        'secondary_enabled' => true,
        'default_nameservers' => [
            ['hostname' => 'ns1.litecontrol.test.', 'ipv4' => '203.0.113.10', 'ipv6' => '2001:db8::10'],
            ['hostname' => 'ns2.litecontrol.test.', 'ipv4' => '203.0.113.11', 'ipv6' => ''],
        ],
    ]);
});

it('forbids non-admin users from accessing zone template and default nameserver routes', function (): void {
    $this->actingAs($this->regularUser)->get('/dns/templates')->assertForbidden();
    $this->actingAs($this->regularUser)->post('/dns/templates', [])->assertForbidden();
    $this->actingAs($this->regularUser)->post('/dns/nameservers', [])->assertForbidden();
});

it('renders the Zone Templates page and auto-seeds the default Standard Web & Mail template', function (): void {
    $this->seed(DnsZoneTemplateSeeder::class);

    $response = $this->actingAs($this->admin)->get('/dns/templates');

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dns/Templates')
            ->has('templates', 1)
            ->where('templates.0.name', 'Standard Web & Mail')
            ->where('templates.0.is_default', true)
            ->has('templates.0.records', 10)
            ->has('defaultNameservers', 2)
            ->where('defaultNameservers.0.hostname', 'ns1.litecontrol.test.')
            ->where('defaultNameservers.0.base_domain', 'litecontrol.test.')
        );
});

it('supports full CRUD on DNS zone templates and updates default nameservers with glue IPs', function (): void {
    $this->seed(DnsZoneTemplateSeeder::class);
    $defaultTemplate = DnsZoneTemplate::query()->firstOrFail();

    // 1. Create a custom template
    $this->actingAs($this->admin)->post('/dns/templates', [
        'name' => 'Minimal Web Only',
        'description' => 'Apex and www A records only',
        'is_default' => false,
        'records' => [
            ['name' => '@', 'type' => 'NS', 'ttl' => 3600, 'priority' => null, 'content' => '{{NS1}}'],
            ['name' => '@', 'type' => 'A', 'ttl' => 600, 'priority' => null, 'content' => '{{IPV4}}'],
            ['name' => 'www', 'type' => 'CNAME', 'ttl' => 600, 'priority' => null, 'content' => '{{DOMAIN}}.'],
        ],
    ])->assertRedirect(route('dns.templates.index'))
        ->assertSessionHas('success');

    $customTemplate = DnsZoneTemplate::query()->where('name', 'Minimal Web Only')->with('records')->firstOrFail();
    expect($customTemplate->records)->toHaveCount(3)
        ->and($customTemplate->is_default)->toBeFalse();

    // 2. Update the custom template
    $this->actingAs($this->admin)->put("/dns/templates/{$customTemplate->id}", [
        'name' => 'Minimal Web + SPF',
        'description' => 'Updated description',
        'is_default' => false,
        'records' => [
            ['name' => '@', 'type' => 'NS', 'ttl' => 3600, 'priority' => null, 'content' => '{{NS1}}'],
            ['name' => '@', 'type' => 'A', 'ttl' => 300, 'priority' => null, 'content' => '{{IPV4}}'],
        ],
    ])->assertRedirect(route('dns.templates.index'))
        ->assertSessionHas('success');

    expect($customTemplate->refresh()->name)->toBe('Minimal Web + SPF')
        ->and($customTemplate->records()->count())->toBe(2);

    // 3. Set custom template as default
    $this->actingAs($this->admin)->post("/dns/templates/{$customTemplate->id}/default")
        ->assertRedirect(route('dns.templates.index'))
        ->assertSessionHas('success');

    expect($customTemplate->refresh()->is_default)->toBeTrue()
        ->and($defaultTemplate->refresh()->is_default)->toBeFalse();

    // 4. Delete custom template -> default falls back to Standard Web & Mail
    $this->actingAs($this->admin)->delete("/dns/templates/{$customTemplate->id}")
        ->assertRedirect(route('dns.templates.index'))
        ->assertSessionHas('success');

    expect(DnsZoneTemplate::query()->count())->toBe(1)
        ->and($defaultTemplate->refresh()->is_default)->toBeTrue();

    // 5. Update Default Nameservers & Glue IPs
    $this->actingAs($this->admin)->post('/dns/nameservers', [
        'default_nameservers' => [
            ['hostname' => 'ns1.paneldomain.com', 'ipv4' => '198.51.100.10', 'ipv6' => '2001:db8::10'],
            ['hostname' => 'ns2.paneldomain.com.', 'ipv4' => '198.51.100.11', 'ipv6' => ''],
        ],
    ])->assertRedirect()
        ->assertSessionHas('success');

    $normalizedNs = PdnsSetting::current()->normalizedDefaultNameservers();
    expect($normalizedNs)->toHaveCount(2)
        ->and($normalizedNs[0])->toMatchArray([
            'hostname' => 'ns1.paneldomain.com.',
            'ipv4' => '198.51.100.10',
            'ipv6' => '2001:db8::10',
            'base_domain' => 'paneldomain.com.',
        ]);
});

it('creates a zone using a template and verifies initial NS, A, AAAA, MX, and TXT RRSets are patched into PowerDNS', function (): void {
    $this->seed(DnsZoneTemplateSeeder::class);
    $template = DnsZoneTemplate::query()->where('name', 'Standard Web & Mail')->firstOrFail();

    $createdZonePayload = null;
    $patchedRrsetsPayload = null;

    Http::fake(function (Request $request) use (&$createdZonePayload, &$patchedRrsetsPayload) {
        if ($request->method() === 'POST' && $request->url() === 'http://127.0.0.1:8081/api/v1/servers/localhost/zones') {
            $createdZonePayload = $request->data();

            return Http::response([
                'id' => $createdZonePayload['name'],
                'name' => $createdZonePayload['name'],
                'kind' => $createdZonePayload['kind'],
                'serial' => 2026112101,
            ], 201);
        }

        if ($request->method() === 'PATCH' && $request->url() === 'http://127.0.0.1:8081/api/v1/servers/localhost/zones/templated-domain.com.') {
            $patchedRrsetsPayload = $request->data();

            return Http::response('', 204);
        }

        return Http::response([], 200);
    });

    $this->actingAs($this->admin)->post('/dns/zones', [
        'zone_type' => 'forward',
        'name' => 'templated-domain.com',
        'kind' => 'Primary',
        'nameservers' => 'ns1.litecontrol.test., ns2.litecontrol.test.',
        'template_id' => $template->id,
        'template_ipv4' => '203.0.113.77',
        'template_ipv6' => '2001:db8::77',
    ])->assertRedirect(route('dns.zones.index'))
        ->assertSessionHas('success');

    expect($createdZonePayload)->not->toBeNull()
        ->and($createdZonePayload['name'])->toBe('templated-domain.com.')
        ->and($patchedRrsetsPayload)->not->toBeNull()
        ->and($patchedRrsetsPayload)->toHaveKey('rrsets');

    $rrsetsByKey = [];
    foreach ($patchedRrsetsPayload['rrsets'] as $rrset) {
        $rrsetsByKey[$rrset['name'].'|'.$rrset['type']] = $rrset;
    }

    expect($rrsetsByKey)->toHaveKeys([
        'templated-domain.com.|NS',
        'templated-domain.com.|A',
        'www.templated-domain.com.|A',
        'mail.templated-domain.com.|A',
        'templated-domain.com.|AAAA',
        'templated-domain.com.|MX',
        'templated-domain.com.|TXT',
    ])
        ->and($rrsetsByKey['templated-domain.com.|NS']['records'])->toBe([
            ['content' => 'ns1.litecontrol.test.', 'disabled' => false],
            ['content' => 'ns2.litecontrol.test.', 'disabled' => false],
        ])
        ->and($rrsetsByKey['templated-domain.com.|A']['records'])->toBe([
            ['content' => '203.0.113.77', 'disabled' => false],
        ])
        ->and($rrsetsByKey['templated-domain.com.|MX']['records'])->toBe([
            ['content' => '10 mail.templated-domain.com.', 'disabled' => false],
        ])
        ->and($rrsetsByKey['templated-domain.com.|TXT']['records'])->toBe([
            ['content' => '"v=spf1 a mx ip4:203.0.113.77 ~all"', 'disabled' => false],
        ]);
});
