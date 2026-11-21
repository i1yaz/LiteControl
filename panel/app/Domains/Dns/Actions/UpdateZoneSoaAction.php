<?php

declare(strict_types=1);

namespace App\Domains\Dns\Actions;

use App\Domains\Dns\Contracts\PowerDnsClientInterface;
use App\Domains\Dns\Exceptions\PowerDnsException;
use App\Domains\Dns\Exceptions\SecondaryZoneReadOnlyException;
use App\Domains\Dns\ValueObjects\RecordNormalizer;
use App\Domains\Dns\ValueObjects\RrsetPayloadBuilder;
use App\Domains\Dns\ValueObjects\UpdateZoneSoaPayload;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

final class UpdateZoneSoaAction
{
    public function __construct(
        private readonly PowerDnsClientInterface $powerDnsClient,
        private readonly RecordNormalizer $recordNormalizer = new RecordNormalizer,
        private readonly RrsetPayloadBuilder $payloadBuilder = new RrsetPayloadBuilder,
        private readonly ?AuditLoggerInterface $auditLogger = null,
    ) {}

    /**
     * Update a zone's apex SOA record via PowerDNS PATCH RRSet (`REPLACE`), keeping serial automatic via `SOA-EDIT-API`
     * unless manual serial override is explicitly enabled.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     * @throws PowerDnsException
     */
    public function handle(
        UpdateZoneSoaPayload $payload,
        ?User $actor = null,
        ?string $ipAddress = null,
    ): array {
        $canonicalZoneId = $payload->zoneId;
        $zone = $this->powerDnsClient->getZone($canonicalZoneId);

        $kind = strtolower(trim((string) ($zone['kind'] ?? 'Native')));
        if (in_array($kind, ['secondary', 'slave'], true)) {
            throw SecondaryZoneReadOnlyException::forSoa($canonicalZoneId);
        }

        $effectiveSerial = $payload->manualSerialOverride && $payload->serial !== null
            ? $payload->serial
            : $this->extractCurrentSerial($zone);

        $normalizedSoaContent = $this->recordNormalizer->normalizeSoaRecord(
            primaryNs: $payload->primaryNs,
            hostmaster: $payload->hostmaster,
            serial: $effectiveSerial,
            refresh: $payload->refresh,
            retry: $payload->retry,
            expire: $payload->expire,
            minimum: $payload->minimum,
            zoneName: $canonicalZoneId,
        );

        $comments = $payload->comment !== null
            ? [['content' => $payload->comment, 'account' => $actor?->username ?? 'litecontrol']]
            : [];

        $soaRrset = $this->payloadBuilder->buildReplace(
            zoneName: $canonicalZoneId,
            name: '@',
            type: 'SOA',
            ttl: $payload->ttl,
            records: [
                ['content' => $normalizedSoaContent, 'disabled' => false],
            ],
            comments: $comments,
        );

        $this->powerDnsClient->patchRrsets($canonicalZoneId, [$soaRrset]);

        $auditDetails = [
            'zone' => $canonicalZoneId,
            'ttl' => $payload->ttl,
            'content' => $normalizedSoaContent,
            'manual_serial_override' => $payload->manualSerialOverride,
            'serial' => $effectiveSerial,
        ];

        Log::info('PowerDNS zone SOA updated', [
            'actor' => $actor?->username ?? 'system',
            'actor_id' => $actor?->id,
            'actor_username' => $actor?->username,
            'action' => 'pdns_update_soa',
            'zone' => $canonicalZoneId,
            'mode' => (string) ($zone['kind'] ?? 'Native'),
            'content' => $normalizedSoaContent,
            'manual_serial_override' => $payload->manualSerialOverride,
            'status' => 'success',
        ]);

        ($this->auditLogger ?? app(AuditLoggerInterface::class))->log([
            'user_id' => $actor?->id,
            'action' => 'pdns_update_soa',
            'command' => 'dns.zones.soa.update',
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => json_encode($auditDetails, JSON_THROW_ON_ERROR),
        ]);

        return $soaRrset;
    }

    /**
     * @param  array<string, mixed>  $zone
     */
    private function extractCurrentSerial(array $zone): int
    {
        $rawRrsets = isset($zone['rrsets']) && is_array($zone['rrsets']) ? $zone['rrsets'] : [];

        foreach ($rawRrsets as $rrset) {
            if (! is_array($rrset) || strtoupper((string) ($rrset['type'] ?? '')) !== 'SOA') {
                continue;
            }

            $records = isset($rrset['records']) && is_array($rrset['records']) ? $rrset['records'] : [];
            $firstContent = isset($records[0]['content']) && is_string($records[0]['content'])
                ? trim($records[0]['content'])
                : '';

            if ($firstContent !== '') {
                $parts = preg_split('/\s+/', $firstContent);
                if (is_array($parts) && isset($parts[2]) && ctype_digit($parts[2])) {
                    return (int) $parts[2];
                }
            }
        }

        if (isset($zone['serial']) && is_numeric($zone['serial']) && (int) $zone['serial'] > 0) {
            return (int) $zone['serial'];
        }

        return (int) (now()->format('Ymd').'01');
    }
}
