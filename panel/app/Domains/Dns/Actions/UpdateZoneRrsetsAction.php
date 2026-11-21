<?php

declare(strict_types=1);

namespace App\Domains\Dns\Actions;

use App\Domains\Dns\Contracts\PowerDnsClientInterface;
use App\Domains\Dns\Exceptions\PowerDnsException;
use App\Domains\Dns\Exceptions\SecondaryZoneReadOnlyException;
use App\Domains\Dns\ValueObjects\RrsetPayloadBuilder;
use App\Domains\Dns\ValueObjects\ZoneNameNormalizer;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

final class UpdateZoneRrsetsAction
{
    public function __construct(
        private readonly PowerDnsClientInterface $powerDnsClient,
        private readonly ZoneNameNormalizer $zoneNameNormalizer = new ZoneNameNormalizer,
        private readonly RrsetPayloadBuilder $payloadBuilder = new RrsetPayloadBuilder,
        private readonly ?AuditLoggerInterface $auditLogger = null,
    ) {}

    /**
     * Validate, normalize, and atomically PATCH one or more RRSets (`REPLACE`, `DELETE`, `EXTEND`, `PRUNE`) on a zone.
     *
     * @param  list<array<string, mixed>>  $operations
     * @return array{
     *     zone: string,
     *     operations_count: int,
     *     rrsets: list<array<string, mixed>>
     * }
     *
     * @throws InvalidArgumentException
     * @throws PowerDnsException
     */
    public function handle(
        string $zoneId,
        array $operations,
        ?User $actor = null,
        ?string $ipAddress = null,
    ): array {
        $canonicalZoneId = $this->zoneNameNormalizer->ensureTrailingDot($zoneId);
        $zone = $this->powerDnsClient->getZone($canonicalZoneId);

        $kind = strtolower(trim((string) ($zone['kind'] ?? 'Native')));
        if (in_array($kind, ['secondary', 'slave'], true)) {
            throw SecondaryZoneReadOnlyException::forRecords($canonicalZoneId);
        }

        $existingRrsets = isset($zone['rrsets']) && is_array($zone['rrsets']) ? $zone['rrsets'] : [];

        $patchPayload = $this->payloadBuilder->buildBatchPatchPayload(
            zoneName: $canonicalZoneId,
            operations: $operations,
            existingRrsets: $existingRrsets,
        );

        $builtRrsets = $patchPayload['rrsets'];
        $this->powerDnsClient->patchRrsets($canonicalZoneId, $builtRrsets);

        $summaryItems = array_map(
            static fn (array $item): array => [
                'name' => $item['name'],
                'type' => $item['type'],
                'changetype' => $item['changetype'],
                'records_count' => isset($item['records']) && is_array($item['records']) ? count($item['records']) : 0,
            ],
            $builtRrsets
        );

        $auditDetails = [
            'zone' => $canonicalZoneId,
            'kind' => (string) ($zone['kind'] ?? 'Native'),
            'operations_count' => count($builtRrsets),
            'operations' => $summaryItems,
        ];

        Log::info('PowerDNS zone RRSets updated', [
            'actor' => $actor?->username ?? 'system',
            'actor_id' => $actor?->id,
            'actor_username' => $actor?->username,
            'action' => 'pdns_update_rrsets',
            'zone' => $canonicalZoneId,
            'mode' => (string) ($zone['kind'] ?? 'Native'),
            'operations_count' => count($builtRrsets),
            'operations' => $summaryItems,
            'status' => 'success',
        ]);

        ($this->auditLogger ?? app(AuditLoggerInterface::class))->log([
            'user_id' => $actor?->id,
            'action' => 'pdns_update_rrsets',
            'command' => 'dns.zones.records.patch',
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => json_encode($auditDetails, JSON_THROW_ON_ERROR),
        ]);

        return [
            'zone' => $canonicalZoneId,
            'operations_count' => count($builtRrsets),
            'rrsets' => $builtRrsets,
        ];
    }
}
