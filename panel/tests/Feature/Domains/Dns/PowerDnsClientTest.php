<?php

declare(strict_types=1);

use App\Domains\Dns\Contracts\PowerDnsClientInterface;
use App\Domains\Dns\Exceptions\PowerDnsException;
use App\Domains\Dns\Infrastructure\PowerDnsClient;
use App\Domains\Dns\Models\PdnsSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('binds PowerDnsClientInterface to PowerDnsClient in container', function (): void {
    $client = app(PowerDnsClientInterface::class);

    expect($client)->toBeInstanceOf(PowerDnsClient::class);
});

it('encrypts api_key at rest and hides secrets when serialized to array or json', function (): void {
    $rawKey = str_repeat('a1b2', 16);
    $rawPassword = 'super-secret-web-password';

    $setting = PdnsSetting::current();
    $setting->update([
        'host' => '127.0.0.1',
        'port' => 8081,
        'api_key' => $rawKey,
        'webserver_password' => $rawPassword,
        'default_nameservers' => [
            ['hostname' => 'ns1.example.com.', 'ipv4' => '203.0.113.10', 'ipv6' => '2001:db8::10'],
            ['hostname' => 'ns2.example.com.', 'ipv4' => '203.0.113.11', 'ipv6' => null],
        ],
    ]);

    $rawDbRow = DB::table('pdns_settings')->where('id', $setting->id)->first();

    expect($rawDbRow)->not->toBeNull()
        ->and($rawDbRow->api_key)->not->toBe($rawKey)
        ->and($rawDbRow->webserver_password)->not->toBe($rawPassword)
        ->and($setting->fresh()->api_key)->toBe($rawKey)
        ->and($setting->fresh()->webserver_password)->toBe($rawPassword)
        ->and($setting->fresh()->hasApiKey())->toBeTrue()
        ->and($setting->fresh()->default_nameservers)->toHaveCount(2)
        ->and($setting->fresh()->toArray())->not->toHaveKey('api_key')
        ->and($setting->fresh()->toArray())->not->toHaveKey('webserver_password');
});

it('sends explicit Accept, Content-Type, and X-API-Key headers on every PowerDNS request', function (): void {
    $secretKey = str_repeat('c3d4', 16);
    $setting = PdnsSetting::current();
    $setting->update([
        'host' => '127.0.0.1',
        'port' => 8081,
        'api_key' => $secretKey,
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
        ], 200),
    ]);

    $client = app(PowerDnsClientInterface::class);

    $server = $client->getServer();
    $config = $client->getConfig();

    expect($server)
        ->toHaveKey('id', 'localhost')
        ->toHaveKey('daemon_type', 'authoritative')
        ->and($config)->toBe([
            ['type' => 'ConfigSetting', 'name' => 'launch', 'value' => 'gmysql'],
        ]);

    Http::assertSentCount(2);
    Http::assertSent(function (Request $request) use ($secretKey): bool {
        return $request->hasHeader('Accept', 'application/json')
            && $request->hasHeader('Content-Type', 'application/json')
            && $request->hasHeader('X-API-Key', $secretKey);
    });
});

it('executes zone lifecycle and rrset patch endpoints cleanly', function (): void {
    $secretKey = str_repeat('e5f6', 16);
    PdnsSetting::current()->update([
        'api_key' => $secretKey,
    ]);

    Http::fake([
        'http://127.0.0.1:8081/api/v1/servers/localhost/zones*' => function (Request $request) {
            if ($request->method() === 'GET' && $request->url() === 'http://127.0.0.1:8081/api/v1/servers/localhost/zones') {
                return Http::response([
                    ['id' => 'example.com.', 'name' => 'example.com.', 'kind' => 'Native', 'serial' => 2026112101],
                ], 200);
            }

            if ($request->method() === 'POST' && $request->url() === 'http://127.0.0.1:8081/api/v1/servers/localhost/zones') {
                return Http::response([
                    'id' => 'example.com.',
                    'name' => 'example.com.',
                    'kind' => $request['kind'] ?? 'Native',
                    'serial' => 2026112101,
                    'rrsets' => [],
                ], 201);
            }

            if ($request->method() === 'GET' && $request->url() === 'http://127.0.0.1:8081/api/v1/servers/localhost/zones/example.com.') {
                return Http::response([
                    'id' => 'example.com.',
                    'name' => 'example.com.',
                    'kind' => 'Primary',
                    'serial' => 2026112102,
                    'rrsets' => [
                        [
                            'name' => 'www.example.com.',
                            'type' => 'A',
                            'ttl' => 3600,
                            'records' => [['content' => '203.0.113.10', 'disabled' => false]],
                        ],
                    ],
                ], 200);
            }

            if (in_array($request->method(), ['PUT', 'PATCH', 'DELETE'], true)) {
                return Http::response('', 204);
            }

            return Http::response(['error' => 'Unexpected request'], 500);
        },
    ]);

    $client = app(PowerDnsClientInterface::class);

    $zones = $client->listZones();
    expect($zones)->toHaveCount(1)
        ->and($zones[0]['id'])->toBe('example.com.');

    $created = $client->createZone([
        'name' => 'example.com.',
        'kind' => 'Native',
        'nameservers' => ['ns1.example.com.', 'ns2.example.com.'],
    ]);
    expect($created)->toHaveKey('id', 'example.com.');

    $client->updateZone('example.com.', [
        'kind' => 'Primary',
        'soa_edit_api' => 'DEFAULT',
    ]);

    $client->patchRrsets('example.com.', [
        [
            'name' => 'www.example.com.',
            'type' => 'A',
            'ttl' => 3600,
            'changetype' => 'REPLACE',
            'records' => [
                ['content' => '203.0.113.10', 'disabled' => false],
            ],
        ],
    ]);

    $zone = $client->getZone('example.com.');
    expect($zone)->toHaveKey('kind', 'Primary')
        ->and($zone['rrsets'])->toHaveCount(1);

    $client->deleteZone('example.com.');

    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
        && $r->url() === 'http://127.0.0.1:8081/api/v1/servers/localhost/zones/example.com.'
        && isset($r['rrsets'][0]['changetype'])
        && $r['rrsets'][0]['changetype'] === 'REPLACE'
    );

    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
        && $r->url() === 'http://127.0.0.1:8081/api/v1/servers/localhost/zones/example.com.'
    );
});

it('searches cross-zone data and exports BIND zone definitions via PowerDnsClient', function (): void {
    PdnsSetting::current()->update([
        'api_key' => str_repeat('a1b2', 16),
    ]);

    Http::fake([
        'http://127.0.0.1:8081/api/v1/servers/localhost/search-data*' => Http::response([
            [
                'object_type' => 'record',
                'zone_id' => 'example.com.',
                'name' => 'www.example.com.',
                'type' => 'A',
                'content' => '203.0.113.10',
            ],
        ], 200),
        'http://127.0.0.1:8081/api/v1/servers/localhost/zones/example.com./export' => Http::response([
            'zone' => "example.com.\t3600\tIN\tSOA\tns1.example.com. hostmaster.example.com. 1 10800 3600 604800 3600\n",
        ], 200),
    ]);

    $client = app(PowerDnsClientInterface::class);

    $matches = $client->searchData('www.example');
    expect($matches)->toHaveCount(1)
        ->and($matches[0]['name'])->toBe('www.example.com.');

    $exported = $client->exportZone('example.com.');
    expect($exported)->toContain('IN	SOA	ns1.example.com.');
});

it('maps PowerDNS error responses into typed PowerDnsException and redacts X-API-Key from logs', function (): void {
    $secretKey = 'deadbeefcafebabe0123456789abcdef0123456789abcdef0123456789abcdef';
    PdnsSetting::current()->update([
        'api_key' => $secretKey,
    ]);

    Log::spy();

    Http::fake([
        'http://127.0.0.1:8081/api/v1/servers/localhost/zones/example.com.' => Http::response([
            'error' => 'Record www.example.com./A: Not in expected format (parsed as \'not-an-ip\')',
            'errors' => [
                'Invalid IPv4 address not-an-ip',
                'RRSet validation failed',
            ],
        ], 422),
    ]);

    $client = app(PowerDnsClientInterface::class);

    try {
        $client->patchRrsets('example.com.', [
            [
                'name' => 'www.example.com.',
                'type' => 'A',
                'ttl' => 3600,
                'changetype' => 'REPLACE',
                'records' => [['content' => 'not-an-ip', 'disabled' => false]],
            ],
        ]);
        $this->fail('Expected PowerDnsException was not thrown.');
    } catch (PowerDnsException $e) {
        expect($e->getStatusCode())->toBe(422)
            ->and($e->getMethod())->toBe('PATCH')
            ->and($e->getEndpoint())->toBe('/api/v1/servers/localhost/zones/example.com.')
            ->and($e->getApiError())->toContain("Not in expected format (parsed as 'not-an-ip')")
            ->and($e->getErrors())->toBe([
                'Invalid IPv4 address not-an-ip',
                'RRSet validation failed',
            ])
            ->and($e->getMessage())->toContain('422')
            ->and($e->getMessage())->toContain('Invalid IPv4 address not-an-ip')
            ->and($e->getMessage())->not->toContain($secretKey);
    }

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(function (string $message, array $context) use ($secretKey): bool {
            $serialized = json_encode($context, JSON_THROW_ON_ERROR);

            return $message === 'PowerDNS API returned error response'
                && ($context['headers']['X-API-Key'] ?? null) === '[REDACTED]'
                && ! str_contains($serialized, $secretKey);
        });
});

it('redacts api_key on connection failures and throws PowerDnsException', function (): void {
    $secretKey = '99887766554433221100ffeeddccbbaa99887766554433221100ffeeddccbbaa';
    PdnsSetting::current()->update([
        'api_key' => $secretKey,
    ]);

    Log::spy();

    Http::fake(function () use ($secretKey) {
        throw new ConnectionException("cURL error 7: Failed to connect to 127.0.0.1 port 8081 (key={$secretKey})");
    });

    $client = app(PowerDnsClientInterface::class);

    try {
        $client->getServer();
        $this->fail('Expected PowerDnsException was not thrown.');
    } catch (PowerDnsException $e) {
        expect($e->getStatusCode())->toBe(0)
            ->and($e->getMessage())->toContain('[REDACTED]')
            ->and($e->getMessage())->not->toContain($secretKey);
    }

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(function (string $message, array $context) use ($secretKey): bool {
            $serialized = json_encode($context, JSON_THROW_ON_ERROR);

            return $message === 'PowerDNS API connection failed'
                && ($context['headers']['X-API-Key'] ?? null) === '[REDACTED]'
                && ! str_contains($serialized, $secretKey);
        });
});

it('fails fast with PowerDnsException when api_key is not configured', function (): void {
    PdnsSetting::current()->update([
        'api_key' => null,
    ]);

    Http::fake();

    $client = app(PowerDnsClientInterface::class);

    expect(fn () => $client->getServer())
        ->toThrow(PowerDnsException::class, 'PowerDNS API key is not configured.');

    Http::assertNothingSent();
});
