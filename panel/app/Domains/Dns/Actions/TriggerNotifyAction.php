<?php

declare(strict_types=1);

namespace App\Domains\Dns\Actions;

use App\Domains\Dns\Contracts\PowerDnsClientInterface;
use App\Domains\Dns\Exceptions\PowerDnsException;
use App\Domains\Dns\ValueObjects\ZoneNameNormalizer;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

final class TriggerNotifyAction
{
    public function __construct(
        private readonly PowerDnsClientInterface $powerDnsClient,
        private readonly ZoneNameNormalizer $zoneNameNormalizer = new ZoneNameNormalizer,
        private readonly ?AuditLoggerInterface $auditLogger = null,
    ) {}

    /**
     * Trigger an outbound DNS NOTIFY (`PUT /api/v1/servers/localhost/zones/{zone_id}/notify`) for a Primary zone.
     *
     * @return array{zone: string, kind: string, result: string}
     *
     * @throws InvalidArgumentException
     * @throws PowerDnsException
     */
    public function handle(
        string $zoneId,
        ?User $actor = null,
        ?string $ipAddress = null,
    ): array {
        $canonicalZoneId = $this->zoneNameNormalizer->ensureTrailingDot($zoneId);
        $zone = $this->powerDnsClient->getZone($canonicalZoneId);

        $rawKind = strtolower(trim((string) ($zone['kind'] ?? 'Native')));
        if (! in_array($rawKind, ['primary', 'master'], true)) {
            throw new InvalidArgumentException(
                sprintf('Outbound DNS NOTIFY is only supported for Primary zones (zone "%s" is currently %s).', $canonicalZoneId, (string) ($zone['kind'] ?? 'Native'))
            );
        }

        $apiResponse = $this->powerDnsClient->notifyZone($canonicalZoneId);
        $resultMessage = isset($apiResponse['result']) && is_string($apiResponse['result']) && trim($apiResponse['result']) !== ''
            ? trim($apiResponse['result'])
            : 'Notification queued';

        $auditDetails = [
            'zone' => $canonicalZoneId,
            'kind' => 'Primary',
            'result' => $resultMessage,
        ];

        Log::info('PowerDNS outbound zone NOTIFY triggered', [
            'actor_id' => $actor?->id,
            'actor_username' => $actor?->username,
            'zone' => $canonicalZoneId,
            'mode' => 'Primary',
            'result' => $resultMessage,
            'status' => 'success',
        ]);

        ($this->auditLogger ?? app(AuditLoggerInterface::class))->log([
            'user_id' => $actor?->id,
            'action' => 'pdns_trigger_notify',
            'command' => 'dns.zones.notify',
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => json_encode($auditDetails, JSON_THROW_ON_ERROR),
        ]);

        return $auditDetails;
    }
}
