<?php

declare(strict_types=1);

use App\Domains\Dns\Contracts\PdnsDaemonDriverInterface;
use App\Domains\Dns\Models\PdnsSetting;
use App\Domains\Identity\Models\User;
use App\Exceptions\System\LcCliException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = User::factory()->create([
        'username' => 'pdnsadmin',
        'role' => 'admin',
    ]);

    $this->regularUser = User::factory()->create([
        'username' => 'pdnsuser',
        'role' => 'user',
    ]);
});

it('renders the DNS Settings Inertia page for admins without exposing raw secrets', function (): void {
    $secretKey = str_repeat('a1b2', 16);
    PdnsSetting::current()->update([
        'host' => '127.0.0.1',
        'port' => 8081,
        'api_key' => $secretKey,
        'webserver_password' => 'secret-stats-pwd',
        'primary_enabled' => true,
        'secondary_enabled' => false,
        'autosecondary_enabled' => false,
    ]);

    Http::fake([
        'http://127.0.0.1:8081/api/v1/servers/localhost' => Http::response([
            'type' => 'Server',
            'id' => 'localhost',
            'daemon_type' => 'authoritative',
            'version' => '4.9.1',
        ], 200),
        'http://127.0.0.1:8081/api/v1/servers/localhost/config' => Http::response([
            ['type' => 'ConfigSetting', 'name' => 'launch', 'value' => 'gmysql'],
            ['type' => 'ConfigSetting', 'name' => 'gmysql-dnssec', 'value' => 'yes'],
            ['type' => 'ConfigSetting', 'name' => 'api-key', 'value' => $secretKey],
        ], 200),
    ]);

    $response = $this->actingAs($this->admin)->get('/dns/settings');

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dns/Settings')
            ->where('settings.host', '127.0.0.1')
            ->where('settings.port', 8081)
            ->where('settings.has_api_key', true)
            ->where('settings.has_webserver_password', true)
            ->missing('settings.api_key')
            ->missing('settings.webserver_password')
            ->where('daemon.systemd_active', true)
            ->where('apiHealth.connected', true)
            ->where('apiHealth.server.version', '4.9.1')
            ->where('apiHealth.backend.launch', 'gmysql')
            ->where('apiHealth.backend.dnssec_enabled', true)
            ->missing('apiHealth.backend.settings.api-key')
        );
});

it('forbids non-admin users from accessing DNS settings routes', function (): void {
    $this->actingAs($this->regularUser)->get('/dns/settings')->assertForbidden();
    $this->actingAs($this->regularUser)->post('/dns/settings', [])->assertForbidden();
    $this->actingAs($this->regularUser)->post('/dns/settings/rotate-key')->assertForbidden();
    $this->actingAs($this->regularUser)->post('/dns/settings/test-connection')->assertForbidden();
});

it('validates and applies PowerDNS daemon configuration via ApplyPdnsConfigAction', function (): void {
    $existingKey = str_repeat('c3d4', 16);
    PdnsSetting::current()->update([
        'api_key' => $existingKey,
    ]);

    $capturedPayloads = [];
    $fakeDriver = new class($capturedPayloads) implements PdnsDaemonDriverInterface
    {
        /** @var list<array<string, mixed>> */
        public array $payloads = [];

        public function __construct(array &$payloads)
        {
            $this->payloads = &$payloads;
        }

        public function isMockMode(): bool
        {
            return true;
        }

        public function getStatus(): array
        {
            return ['systemd_active' => true];
        }

        public function applyConfig(array $payload): array
        {
            $this->payloads[] = $payload;

            return ['validated' => true, 'service_state' => 'active'];
        }
    };

    $this->app->instance(PdnsDaemonDriverInterface::class, $fakeDriver);

    $response = $this->actingAs($this->admin)->post('/dns/settings', [
        'host' => '127.0.0.1',
        'port' => 8081,
        'webserver_allow_from' => '127.0.0.1, ::1',
        'webserver_password' => '',
        'primary_enabled' => true,
        'secondary_enabled' => true,
        'autosecondary_enabled' => true,
        'allow_axfr_ips' => '198.51.100.10, 203.0.113.0/24',
        'also_notify' => '198.51.100.10:5300',
    ]);

    $response->assertRedirect()
        ->assertSessionHas('success');

    expect($capturedPayloads)->toHaveCount(1)
        ->and($capturedPayloads[0])->toMatchArray([
            'api_key' => $existingKey,
            'webserver_address' => '127.0.0.1',
            'webserver_port' => 8081,
            'webserver_allow_from' => '127.0.0.1,::1',
            'primary' => true,
            'secondary' => true,
            'autosecondary' => true,
            'allow_axfr_ips' => '198.51.100.10,203.0.113.0/24',
            'also_notify' => '198.51.100.10:5300',
        ]);

    $updated = PdnsSetting::current();
    expect($updated->secondary_enabled)->toBeTrue()
        ->and($updated->autosecondary_enabled)->toBeTrue()
        ->and($updated->allow_axfr_ips)->toBe('198.51.100.10,203.0.113.0/24')
        ->and($updated->also_notify)->toBe('198.51.100.10:5300');

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $this->admin->id,
        'action' => 'pdns_apply_config',
        'command' => 'dns.settings.update',
        'status' => 'success',
    ]);
});

it('enforces non-loopback bind address security rules in UpdatePdnsConfigRequest', function (): void {
    $response = $this->actingAs($this->admin)->post('/dns/settings', [
        'host' => '192.0.2.50',
        'port' => 8081,
        'webserver_allow_from' => '0.0.0.0/0',
        'webserver_password' => '',
        'primary_enabled' => true,
        'secondary_enabled' => false,
        'autosecondary_enabled' => false,
    ]);

    $response->assertSessionHasErrors(['webserver_allow_from', 'webserver_password']);
});

it('does not update database when OS applyConfig throws LcCliException', function (): void {
    $setting = PdnsSetting::current();
    $setting->update([
        'port' => 8081,
        'api_key' => str_repeat('ab', 32),
    ]);

    $failingDriver = new class implements PdnsDaemonDriverInterface
    {
        public function isMockMode(): bool
        {
            return false;
        }

        public function getStatus(): array
        {
            return [];
        }

        public function applyConfig(array $payload): array
        {
            throw new LcCliException('pdns_server --config-check failed (rolled back)', 'pdns', ['apply-config']);
        }
    };

    $this->app->instance(PdnsDaemonDriverInterface::class, $failingDriver);

    $response = $this->actingAs($this->admin)->post('/dns/settings', [
        'host' => '127.0.0.1',
        'port' => 8099,
        'webserver_allow_from' => '127.0.0.1,::1',
        'primary_enabled' => true,
        'secondary_enabled' => false,
        'autosecondary_enabled' => false,
    ]);

    $response->assertRedirect()
        ->assertSessionHas('error', '[lc-cli pdns] pdns_server --config-check failed (rolled back)');

    expect($setting->fresh()->port)->toBe(8081);
});

it('rotates API key atomically and commits new key when /servers/localhost returns 200 OK', function (): void {
    $oldKey = str_repeat('11', 32);
    $setting = PdnsSetting::current();
    $setting->update([
        'host' => '127.0.0.1',
        'port' => 8081,
        'api_key' => $oldKey,
    ]);

    $appliedPayloads = [];
    $fakeDriver = new class($appliedPayloads) implements PdnsDaemonDriverInterface
    {
        /** @var list<array<string, mixed>> */
        public array $payloads = [];

        public function __construct(array &$payloads)
        {
            $this->payloads = &$payloads;
        }

        public function isMockMode(): bool
        {
            return true;
        }

        public function getStatus(): array
        {
            return [];
        }

        public function applyConfig(array $payload): array
        {
            $this->payloads[] = $payload;

            return ['validated' => true, 'service_state' => 'active'];
        }
    };

    $this->app->instance(PdnsDaemonDriverInterface::class, $fakeDriver);

    Http::fake(function (Request $request) use (&$appliedPayloads) {
        $latestAppliedKey = $appliedPayloads[count($appliedPayloads) - 1]['api_key'] ?? '';

        if ($request->url() === 'http://127.0.0.1:8081/api/v1/servers/localhost'
            && $request->hasHeader('X-API-Key', $latestAppliedKey)
        ) {
            return Http::response([
                'type' => 'Server',
                'id' => 'localhost',
                'daemon_type' => 'authoritative',
                'version' => '4.9.1',
            ], 200);
        }

        return Http::response(['error' => 'Unauthorized'], 401);
    });

    $response = $this->actingAs($this->admin)->post('/dns/settings/rotate-key');

    $response->assertRedirect()
        ->assertSessionHas('success');

    $newKey = $setting->fresh()->api_key;

    expect($appliedPayloads)->toHaveCount(1)
        ->and($newKey)->not->toBe($oldKey)
        ->and(strlen((string) $newKey))->toBe(64)
        ->and($appliedPayloads[0]['api_key'])->toBe($newKey);

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $this->admin->id,
        'action' => 'pdns_rotate_api_key',
        'command' => 'dns.settings.rotate-key',
        'status' => 'success',
    ]);
});

it('rolls back OS drop-in config to prior API key and preserves DB key when post-rotation health probe fails', function (): void {
    $oldKey = str_repeat('22', 32);
    $setting = PdnsSetting::current();
    $setting->update([
        'host' => '127.0.0.1',
        'port' => 8081,
        'api_key' => $oldKey,
    ]);

    $appliedPayloads = [];
    $fakeDriver = new class($appliedPayloads) implements PdnsDaemonDriverInterface
    {
        /** @var list<array<string, mixed>> */
        public array $payloads = [];

        public function __construct(array &$payloads)
        {
            $this->payloads = &$payloads;
        }

        public function isMockMode(): bool
        {
            return true;
        }

        public function getStatus(): array
        {
            return [];
        }

        public function applyConfig(array $payload): array
        {
            $this->payloads[] = $payload;

            return ['validated' => true, 'service_state' => 'active'];
        }
    };

    $this->app->instance(PdnsDaemonDriverInterface::class, $fakeDriver);

    Http::fake([
        'http://127.0.0.1:8081/api/v1/servers/localhost' => Http::response([
            'error' => 'Unauthorized',
        ], 401),
    ]);

    $response = $this->actingAs($this->admin)->post('/dns/settings/rotate-key');

    $response->assertRedirect()
        ->assertSessionHas('error');

    // First call applied the candidate key; second call automatically rolled back to $oldKey
    expect($appliedPayloads)->toHaveCount(2)
        ->and($appliedPayloads[0]['api_key'])->not->toBe($oldKey)
        ->and($appliedPayloads[1]['api_key'])->toBe($oldKey)
        ->and($setting->fresh()->api_key)->toBe($oldKey);

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $this->admin->id,
        'action' => 'pdns_rotate_api_key',
        'command' => 'dns.settings.rotate-key',
        'status' => 'error',
    ]);
});

it('tests PowerDNS HTTP API connectivity and reports active backend', function (): void {
    PdnsSetting::current()->update([
        'host' => '127.0.0.1',
        'port' => 8081,
        'api_key' => str_repeat('55', 32),
    ]);

    Http::fake([
        'http://127.0.0.1:8081/api/v1/servers/localhost' => Http::response([
            'type' => 'Server',
            'id' => 'localhost',
            'daemon_type' => 'authoritative',
            'version' => '4.9.1',
        ], 200),
        'http://127.0.0.1:8081/api/v1/servers/localhost/config' => Http::response([
            ['type' => 'ConfigSetting', 'name' => 'launch', 'value' => 'gsqlite3'],
            ['type' => 'ConfigSetting', 'name' => 'gsqlite3-dnssec', 'value' => 'yes'],
        ], 200),
    ]);

    $response = $this->actingAs($this->admin)->post('/dns/settings/test-connection');

    $response->assertRedirect()
        ->assertSessionHas('success', 'Connected to PowerDNS authoritative v4.9.1 (active backend: gsqlite3).');
});
