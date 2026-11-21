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

final class RectifyZoneAction
{
    public function __construct(
        private readonly PowerDnsClientInterface $powerDnsClient,
        private readonly ZoneNameNormalizer $zoneNameNormalizer = new ZoneNameNormalizer,
        private readonly ?AuditLoggerInterface $auditLogger = null,
    ) {}

    /**
     * Rectify a zone's `ordername` and `auth` fields (`PUT /api/v1/servers/localhost/zones/{zone_id}/rectify`).
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

        $kind = $this->normalizeKind((string) ($zone['kind'] ?? 'Native'));

        $apiResponse = $this->powerDnsClient->rectifyZone($canonicalZoneId);
        $resultMessage = isset($apiResponse['result']) && is_string($apiResponse['result']) && trim($apiResponse['result']) !== ''
            ? trim($apiResponse['result'])
            : 'Rectified';

        $auditDetails = [
            'zone' => $canonicalZoneId,
            'kind' => $kind,
            'result' => $resultMessage,
        ];

        Log::info('PowerDNS zone rectified', [
            'actor' => $actor?->username ?? 'system',
            'actor_id' => $actor?->id,
            'actor_username' => $actor?->username,
            'action' => 'pdns_rectify_zone',
            'zone' => $canonicalZoneId,
            'mode' => $kind,
            'result' => $resultMessage,
            'status' => 'success',
        ]);

        ($this->auditLogger ?? app(AuditLoggerInterface::class))->log([
            'user_id' => $actor?->id,
            'action' => 'pdns_rectify_zone',
            'command' => 'dns.zones.rectify',
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => json_encode($auditDetails, JSON_THROW_ON_ERROR),
        ]);

        return $auditDetails;
    }

    private function normalizeKind(string $rawKind): string
    {
        return match (strtolower(trim($rawKind))) {
            'primary', 'master' => 'Primary',
            'secondary', 'slave' => 'Secondary',
            default => 'Native',
        };
    }
}
