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

final class TriggerAxfrRetrieveAction
{
    public function __construct(
        private readonly PowerDnsClientInterface $powerDnsClient,
        private readonly ZoneNameNormalizer $zoneNameNormalizer = new ZoneNameNormalizer,
        private readonly ?AuditLoggerInterface $auditLogger = null,
    ) {}

    /**
     * Trigger an immediate AXFR/IXFR transfer retrieval (`PUT /api/v1/servers/localhost/zones/{zone_id}/axfr-retrieve`)
     * from upstream masters for a Secondary zone.
     *
     * @return array{zone: string, kind: string, masters: list<string>, result: string}
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
        if (! in_array($rawKind, ['secondary', 'slave'], true)) {
            throw new InvalidArgumentException(
                sprintf('AXFR retrieval is only supported for Secondary zones (zone "%s" is currently %s).', $canonicalZoneId, (string) ($zone['kind'] ?? 'Native'))
            );
        }

        $masters = [];
        if (isset($zone['masters']) && is_array($zone['masters'])) {
            foreach ($zone['masters'] as $master) {
                if (is_string($master) && trim($master) !== '') {
                    $masters[] = trim($master);
                }
            }
        }

        $apiResponse = $this->powerDnsClient->axfrRetrieveZone($canonicalZoneId);
        $resultMessage = isset($apiResponse['result']) && is_string($apiResponse['result']) && trim($apiResponse['result']) !== ''
            ? trim($apiResponse['result'])
            : 'Added retrieval request for domain';

        $auditDetails = [
            'zone' => $canonicalZoneId,
            'kind' => 'Secondary',
            'masters' => $masters,
            'result' => $resultMessage,
        ];

        Log::info('PowerDNS Secondary zone AXFR retrieve triggered', [
            'actor' => $actor?->username ?? 'system',
            'actor_id' => $actor?->id,
            'actor_username' => $actor?->username,
            'action' => 'pdns_trigger_axfr_retrieve',
            'zone' => $canonicalZoneId,
            'mode' => 'Secondary',
            'masters' => $masters,
            'result' => $resultMessage,
            'status' => 'success',
        ]);

        ($this->auditLogger ?? app(AuditLoggerInterface::class))->log([
            'user_id' => $actor?->id,
            'action' => 'pdns_trigger_axfr_retrieve',
            'command' => 'dns.zones.axfr-retrieve',
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => json_encode($auditDetails, JSON_THROW_ON_ERROR),
        ]);

        return $auditDetails;
    }
}
