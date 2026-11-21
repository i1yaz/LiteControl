<?php

declare(strict_types=1);

namespace App\Domains\Dns\Actions;

use App\Domains\Dns\Contracts\PdnsDaemonDriverInterface;
use App\Domains\Dns\Models\PdnsSetting;
use App\Domains\Dns\ValueObjects\PdnsDaemonConfig;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use App\Exceptions\System\LcCliException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ApplyPdnsConfigAction
{
    public function __construct(
        private readonly PdnsDaemonDriverInterface $daemonDriver,
        private readonly ?AuditLoggerInterface $auditLogger = null,
    ) {}

    /**
     * Validate and apply PowerDNS daemon & HTTP API configuration (OS-first, then DB transaction).
     *
     * @throws LcCliException
     */
    public function handle(
        PdnsDaemonConfig $config,
        ?User $actor = null,
        ?string $ipAddress = null,
    ): PdnsSetting {
        $setting = PdnsSetting::current();

        $apiKey = $setting->hasApiKey()
            ? trim((string) $setting->api_key)
            : bin2hex(random_bytes(32));

        $fallbackPassword = $setting->hasWebserverPassword()
            ? (string) $setting->webserver_password
            : null;

        $cliPayload = $config->toCliPayload($apiKey, $fallbackPassword);

        // 1. OS-first mutation via Go CLI shim (writes temp drop-in, runs --config-check, restarts pdns, rolls back on error)
        $this->daemonDriver->applyConfig($cliPayload);

        // 2. Persist updated settings to LiteControl database inside a transaction
        $updated = DB::transaction(function () use ($setting, $config, $apiKey, $fallbackPassword): PdnsSetting {
            $setting->update($config->toModelAttributes($apiKey, $fallbackPassword));

            return $setting->fresh();
        });

        $auditDetails = [
            'host' => $config->host,
            'port' => $config->port,
            'webserver_allow_from' => $config->webserverAllowFrom,
            'primary_enabled' => $config->primaryEnabled,
            'secondary_enabled' => $config->secondaryEnabled,
            'autosecondary_enabled' => $config->autosecondaryEnabled,
            'allow_axfr_ips' => $config->allowAxfrIps,
            'also_notify' => $config->alsoNotify,
            'api_key' => '[REDACTED]',
        ];

        Log::info('PowerDNS daemon configuration applied', [
            'actor_id' => $actor?->id,
            'config' => $auditDetails,
        ]);

        ($this->auditLogger ?? app(AuditLoggerInterface::class))->log([
            'user_id' => $actor?->id,
            'action' => 'pdns_apply_config',
            'command' => 'dns.settings.update',
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => json_encode($auditDetails, JSON_THROW_ON_ERROR),
        ]);

        return $updated;
    }
}
