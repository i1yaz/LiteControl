<?php

declare(strict_types=1);

namespace App\Domains\Dns\Queries;

use App\Domains\Dns\Contracts\PdnsDaemonDriverInterface;
use App\Domains\Dns\Contracts\PowerDnsClientInterface;
use App\Domains\Dns\Exceptions\PowerDnsException;
use App\Domains\Dns\Models\PdnsSetting;
use App\Exceptions\System\LcCliException;

final class GetPdnsHealthQuery
{
    public function __construct(
        private readonly PdnsDaemonDriverInterface $daemonDriver,
        private readonly PowerDnsClientInterface $powerDnsClient,
    ) {}

    /**
     * Inspect OS daemon status (`lc-cli pdns status`) and active PowerDNS HTTP API configuration.
     *
     * @return array{
     *     daemon: array<string, mixed>,
     *     api_health: array{
     *         connected: bool,
     *         server: array<string, mixed>|null,
     *         backend: array<string, mixed>|null,
     *         error: string|null
     *     }
     * }
     */
    public function handle(PdnsSetting $setting): array
    {
        try {
            $daemonStatus = $this->daemonDriver->getStatus();
        } catch (LcCliException $e) {
            $daemonStatus = [
                'distro_family' => 'unknown',
                'config_path' => '',
                'drop_in_dir' => '',
                'drop_in_path' => '',
                'service_name' => 'pdns',
                'systemd_active' => false,
                'systemd_state' => 'error',
                'drop_in_present' => false,
                'include_dir_enabled' => false,
                'port_53' => [
                    'owner' => 'unknown',
                    'conflict' => false,
                    'listeners' => [],
                ],
                'error' => $e->getMessage(),
            ];
        }

        if (! $setting->hasApiKey()) {
            return [
                'daemon' => $daemonStatus,
                'api_health' => [
                    'connected' => false,
                    'server' => null,
                    'backend' => null,
                    'error' => 'PowerDNS API key is not configured yet.',
                ],
            ];
        }

        try {
            $client = $this->powerDnsClient->withSettings($setting);
            $server = $client->getServer();
            $configList = $client->getConfig();

            return [
                'daemon' => $daemonStatus,
                'api_health' => [
                    'connected' => true,
                    'server' => [
                        'id' => (string) ($server['id'] ?? 'localhost'),
                        'daemon_type' => (string) ($server['daemon_type'] ?? 'authoritative'),
                        'version' => (string) ($server['version'] ?? 'unknown'),
                    ],
                    'backend' => self::extractBackendSummary($configList),
                    'error' => null,
                ],
            ];
        } catch (PowerDnsException $e) {
            return [
                'daemon' => $daemonStatus,
                'api_health' => [
                    'connected' => false,
                    'server' => null,
                    'backend' => null,
                    'error' => $e->getMessage(),
                ],
            ];
        }
    }

    /**
     * Parse PowerDNS `/api/v1/servers/localhost/config` items into an active backend readout,
     * redacting any secrets or passwords.
     *
     * @param  list<array<string, mixed>>  $configList
     * @return array{
     *     launch: string,
     *     dnssec_enabled: bool,
     *     settings: array<string, string>
     * }
     */
    public static function extractBackendSummary(array $configList): array
    {
        $map = [];
        foreach ($configList as $item) {
            if (! is_array($item)) {
                continue;
            }
            $name = isset($item['name']) && is_string($item['name']) ? trim($item['name']) : '';
            if ($name === '') {
                continue;
            }
            $value = isset($item['value']) && is_scalar($item['value']) ? (string) $item['value'] : '';
            $map[$name] = $value;
        }

        $launch = trim((string) ($map['launch'] ?? 'unknown'));
        $primaryBackend = trim(explode(',', $launch)[0] ?? 'unknown');

        $dnssecKey = $primaryBackend !== '' && $primaryBackend !== 'unknown'
            ? "{$primaryBackend}-dnssec"
            : null;

        $dnssecEnabled = false;
        if ($dnssecKey !== null && isset($map[$dnssecKey])) {
            $dnssecEnabled = in_array(strtolower(trim($map[$dnssecKey])), ['yes', '1', 'true', 'on'], true);
        } else {
            foreach ($map as $k => $v) {
                if (str_ends_with($k, '-dnssec') && in_array(strtolower(trim($v)), ['yes', '1', 'true', 'on'], true)) {
                    $dnssecEnabled = true;
                    break;
                }
            }
        }

        $interestingKeys = [
            'launch',
            'api',
            'webserver',
            'webserver-address',
            'webserver-port',
            'webserver-allow-from',
            'primary',
            'secondary',
            'autosecondary',
            'allow-axfr-ips',
            'also-notify',
            'default-soa-edit',
            'version-string',
        ];

        if ($dnssecKey !== null) {
            $interestingKeys[] = $dnssecKey;
        }

        $safeSettings = [];
        foreach ($interestingKeys as $key) {
            if (array_key_exists($key, $map) && ! self::isSensitiveConfigKey($key)) {
                $safeSettings[$key] = $map[$key];
            }
        }

        return [
            'launch' => $launch !== '' ? $launch : 'unknown',
            'dnssec_enabled' => $dnssecEnabled,
            'settings' => $safeSettings,
        ];
    }

    private static function isSensitiveConfigKey(string $key): bool
    {
        $lower = strtolower($key);

        return str_contains($lower, 'api-key')
            || str_contains($lower, 'password')
            || str_contains($lower, 'secret');
    }
}
