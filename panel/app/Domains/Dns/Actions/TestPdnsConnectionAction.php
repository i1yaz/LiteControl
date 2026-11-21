<?php

declare(strict_types=1);

namespace App\Domains\Dns\Actions;

use App\Domains\Dns\Contracts\PowerDnsClientInterface;
use App\Domains\Dns\Exceptions\PowerDnsException;
use App\Domains\Dns\Models\PdnsSetting;
use App\Domains\Dns\Queries\GetPdnsHealthQuery;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\Contracts\AuditLoggerInterface;

final class TestPdnsConnectionAction
{
    public function __construct(
        private readonly PowerDnsClientInterface $powerDnsClient,
        private readonly ?AuditLoggerInterface $auditLogger = null,
    ) {}

    /**
     * Probe PowerDNS HTTP API (`/servers/localhost` and `/servers/localhost/config`)
     * to verify connectivity and inspect the active backend.
     *
     * @return array{
     *     server: array{id: string, daemon_type: string, version: string},
     *     backend: array{launch: string, dnssec_enabled: bool, settings: array<string, string>}
     * }
     *
     * @throws PowerDnsException
     */
    public function handle(?User $actor = null, ?string $ipAddress = null): array
    {
        $setting = PdnsSetting::current();
        $client = $this->powerDnsClient->withSettings($setting);

        $server = $client->getServer();
        $configList = $client->getConfig();
        $backend = GetPdnsHealthQuery::extractBackendSummary($configList);

        $result = [
            'server' => [
                'id' => (string) ($server['id'] ?? 'localhost'),
                'daemon_type' => (string) ($server['daemon_type'] ?? 'authoritative'),
                'version' => (string) ($server['version'] ?? 'unknown'),
            ],
            'backend' => $backend,
        ];

        ($this->auditLogger ?? app(AuditLoggerInterface::class))->log([
            'user_id' => $actor?->id,
            'action' => 'pdns_test_connection',
            'command' => 'dns.settings.test-connection',
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => json_encode([
                'host' => $setting->host,
                'port' => $setting->port,
                'version' => $result['server']['version'],
                'launch' => $result['backend']['launch'],
            ], JSON_THROW_ON_ERROR),
        ]);

        return $result;
    }
}
