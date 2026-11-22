<?php

declare(strict_types=1);

namespace App\Domains\Dns\Actions;

use App\Domains\Dns\Contracts\PdnsDaemonDriverInterface;
use App\Domains\Dns\Contracts\PowerDnsClientInterface;
use App\Domains\Dns\Exceptions\PowerDnsException;
use App\Domains\Dns\Models\PdnsSetting;
use App\Domains\Dns\ValueObjects\PdnsDaemonConfig;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use App\Exceptions\System\LcCliException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RotatePdnsApiKeyAction
{
    public function __construct(
        private readonly PdnsDaemonDriverInterface $daemonDriver,
        private readonly PowerDnsClientInterface $powerDnsClient,
        private readonly ?AuditLoggerInterface $auditLogger = null,
    ) {}

    /**
     * Atomically rotate the PowerDNS API key:
     * 1. Generate new 64-char hex key
     * 2. Write drop-in, run `pdns_server --config-check`, swap `99-litecontrol.conf`, and restart `pdns`
     * 3. Probe `GET /api/v1/servers/localhost` with the new key
     * 4. Commit the new key to LiteControl's DB only if the probe returns 200 OK;
     *    otherwise restore the previous drop-in configuration and restart `pdns`.
     *
     * @throws LcCliException
     * @throws PowerDnsException
     */
    public function handle(?User $actor = null, ?string $ipAddress = null): PdnsSetting
    {
        $setting = PdnsSetting::current();
        $previousKey = $setting->hasApiKey() ? trim((string) $setting->api_key) : null;
        $fallbackPassword = $setting->hasWebserverPassword() ? (string) $setting->webserver_password : null;

        $currentConfig = PdnsDaemonConfig::fromSetting($setting);

        do {
            $newKey = bin2hex(random_bytes(32));
        } while ($newKey === $previousKey);

        // Step 1: Apply new key to OS drop-in config & restart pdns
        $this->daemonDriver->applyConfig($currentConfig->toCliPayload($newKey, $fallbackPassword));

        // Step 2: Probe GET /api/v1/servers/localhost with the NEW key before committing to DB
        $candidateSetting = new PdnsSetting([
            'host' => $currentConfig->host,
            'port' => $currentConfig->port,
            'api_key' => $newKey,
        ]);

        try {
            $server = $this->powerDnsClient->withSettings($candidateSetting)->getServer();
        } catch (Throwable $probeException) {
            $rollbackSucceeded = false;

            if ($previousKey !== null && $previousKey !== '') {
                try {
                    $this->daemonDriver->applyConfig($currentConfig->toCliPayload($previousKey, $fallbackPassword));
                    $rollbackSucceeded = true;
                } catch (Throwable $rollbackException) {
                    Log::critical('PowerDNS API key rotation rollback failed', [
                        'actor_id' => $actor?->id,
                        'rollback_error' => $this->redactSecrets($rollbackException->getMessage(), [$newKey, $previousKey]),
                    ]);
                }
            }

            $sanitizedError = $this->redactSecrets($probeException->getMessage(), array_filter([$newKey, $previousKey]));

            Log::error('PowerDNS API key rotation failed health check probe; rolled back to prior key', [
                'actor' => $actor?->username ?? 'system',
                'actor_id' => $actor?->id,
                'actor_username' => $actor?->username,
                'action' => 'pdns_rotate_api_key',
                'zone' => null,
                'mode' => 'daemon',
                'status' => 'error',
                'host' => $currentConfig->host,
                'port' => $currentConfig->port,
                'rolled_back' => $rollbackSucceeded,
                'error' => $sanitizedError,
            ]);

            ($this->auditLogger ?? app(AuditLoggerInterface::class))->log([
                'user_id' => $actor?->id,
                'action' => 'pdns_rotate_api_key',
                'command' => 'dns.settings.rotate-key',
                'status' => 'error',
                'ip_address' => $ipAddress,
                'details' => json_encode([
                    'host' => $currentConfig->host,
                    'port' => $currentConfig->port,
                    'rolled_back' => $rollbackSucceeded,
                    'error' => $sanitizedError,
                ], JSON_THROW_ON_ERROR),
            ]);

            if ($probeException instanceof PowerDnsException) {
                throw new PowerDnsException(
                    message: sprintf(
                        'PowerDNS API key rotation aborted (post-restart health check failed%s): %s',
                        $rollbackSucceeded ? '; rolled back to previous key' : '',
                        $sanitizedError,
                    ),
                    statusCode: $probeException->getStatusCode(),
                    apiError: $probeException->getApiError(),
                    errors: $probeException->getErrors(),
                    method: $probeException->getMethod(),
                    endpoint: $probeException->getEndpoint(),
                    previous: $probeException,
                );
            }

            throw new PowerDnsException(
                message: sprintf(
                    'PowerDNS API key rotation aborted (post-restart health check failed%s): %s',
                    $rollbackSucceeded ? '; rolled back to previous key' : '',
                    $sanitizedError,
                ),
                previous: $probeException,
            );
        }

        // Step 3: Health check returned 200 OK — commit the new key to LiteControl's database
        $updated = DB::transaction(function () use ($setting, $newKey): PdnsSetting {
            $setting->update([
                'api_key' => $newKey,
            ]);

            return $setting->fresh();
        });

        $auditDetails = [
            'host' => $currentConfig->host,
            'port' => $currentConfig->port,
            'server_id' => (string) ($server['id'] ?? 'localhost'),
            'version' => (string) ($server['version'] ?? 'unknown'),
            'api_key' => '[REDACTED]',
        ];

        Log::info('PowerDNS API key rotated and verified', [
            'actor' => $actor?->username ?? 'system',
            'actor_id' => $actor?->id,
            'actor_username' => $actor?->username,
            'action' => 'pdns_rotate_api_key',
            'zone' => null,
            'mode' => 'daemon',
            'status' => 'success',
            'details' => $auditDetails,
        ]);

        ($this->auditLogger ?? app(AuditLoggerInterface::class))->log([
            'user_id' => $actor?->id,
            'action' => 'pdns_rotate_api_key',
            'command' => 'dns.settings.rotate-key',
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => json_encode($auditDetails, JSON_THROW_ON_ERROR),
        ]);

        return $updated;
    }

    /**
     * @param  array<int, string|null>  $secrets
     */
    private function redactSecrets(string $text, array $secrets): string
    {
        foreach ($secrets as $secret) {
            if (is_string($secret) && $secret !== '') {
                $text = str_replace($secret, '[REDACTED]', $text);
            }
        }

        return $text;
    }
}
