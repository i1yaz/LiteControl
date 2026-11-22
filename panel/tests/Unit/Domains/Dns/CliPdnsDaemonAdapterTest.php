<?php

declare(strict_types=1);

use App\Domains\Dns\Contracts\PdnsDaemonDriverInterface;
use App\Domains\Dns\Infrastructure\CliPdnsDaemonAdapter;
use App\Exceptions\System\LcCliException;
use App\Support\Cli\LcCliService;
use Tests\TestCase;

uses(TestCase::class);

it('binds PdnsDaemonDriverInterface to CliPdnsDaemonAdapter in container', function (): void {
    $driver = app(PdnsDaemonDriverInterface::class);

    expect($driver)->toBeInstanceOf(CliPdnsDaemonAdapter::class);
});

it('retrieves PowerDNS daemon status via LcCliService', function (): void {
    $driver = app(PdnsDaemonDriverInterface::class);
    $status = $driver->getStatus();

    expect($status)
        ->toHaveKey('distro_family', 'debian')
        ->toHaveKey('config_path', '/etc/powerdns/pdns.conf')
        ->toHaveKey('drop_in_path', '/etc/powerdns/pdns.d/99-litecontrol.conf')
        ->toHaveKey('systemd_active', true)
        ->and($status['port_53']['conflict'])->toBeFalse();
});

it('applies PowerDNS daemon config payload via LcCliService', function (): void {
    $driver = app(PdnsDaemonDriverInterface::class);
    $result = $driver->applyConfig([
        'api_key' => str_repeat('a1', 32),
        'webserver_address' => '127.0.0.1',
        'webserver_port' => 8081,
        'webserver_allow_from' => '127.0.0.1,::1',
        'primary' => true,
        'secondary' => false,
        'autosecondary' => false,
    ]);

    expect($result)
        ->toHaveKey('validated', true)
        ->toHaveKey('service_state', 'active');
});

it('redacts payload secrets when throwing LcCliException on applyConfig failure', function (): void {
    $secretKey = str_repeat('f9', 32);

    $fakeCli = new class extends LcCliService
    {
        public function __construct() {}

        public function run(string $command, array $args = []): array
        {
            return [
                'status' => 'error',
                'message' => 'pdns_server --config-check failed (rolled back)',
                'data' => null,
            ];
        }
    };

    $adapter = new CliPdnsDaemonAdapter($fakeCli);

    try {
        $adapter->applyConfig(['api_key' => $secretKey]);
        $this->fail('Expected LcCliException was not thrown.');
    } catch (LcCliException $e) {
        expect($e->getMessage())->toContain('pdns_server --config-check failed')
            ->and(json_encode($e->getArgs()))->not->toContain($secretKey)
            ->and($e->getArgs())->toBe(['apply-config', '--payload', '[REDACTED]']);
    }
});
