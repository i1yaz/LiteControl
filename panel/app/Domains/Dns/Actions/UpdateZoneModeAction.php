<?php

declare(strict_types=1);

namespace App\Domains\Dns\Actions;

use App\Domains\Dns\Contracts\PowerDnsClientInterface;
use App\Domains\Dns\Exceptions\PowerDnsException;
use App\Domains\Dns\ValueObjects\UpdateZoneModePayload;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

final class UpdateZoneModeAction
{
    public function __construct(
        private readonly PowerDnsClientInterface $powerDnsClient,
        private readonly ?AuditLoggerInterface $auditLogger = null,
    ) {}

    /**
     * Switch a zone's replication operating mode (`Native`, `Primary`, `Secondary`), update `masters`,
     * manage `ALLOW-AXFR-FROM` and `ALSO-NOTIFY` metadata, and trigger zone rectification when transitioning
     * out of `Secondary` mode.
     *
     * @return array{
     *     zone: string,
     *     previous_kind: string,
     *     kind: string,
     *     masters: list<string>,
     *     allow_axfr_from: list<string>,
     *     also_notify: list<string>,
     *     rectified: bool
     * }
     *
     * @throws InvalidArgumentException
     * @throws PowerDnsException
     */
    public function handle(
        UpdateZoneModePayload $payload,
        ?User $actor = null,
        ?string $ipAddress = null,
    ): array {
        $canonicalZoneId = $payload->zoneId;
        $currentZone = $this->powerDnsClient->getZone($canonicalZoneId);

        $previousKind = $this->normalizeKind((string) ($currentZone['kind'] ?? 'Native'));

        if ($payload->kind === 'Secondary' && $previousKind !== 'Secondary' && ! $payload->confirmOverwrite) {
            throw new InvalidArgumentException(
                'Switching a zone to Secondary mode requires explicit confirmation that the next AXFR transfer will overwrite local records.'
            );
        }

        // 1. Update zone kind, masters, soa_edit_api, and api_rectify via PUT /zones/{zone_id}
        $this->powerDnsClient->updateZone($canonicalZoneId, $payload->toPowerDnsZonePayload());

        // 2. Update per-zone transfer metadata (ALLOW-AXFR-FROM & ALSO-NOTIFY)
        if ($payload->kind === 'Primary') {
            $this->powerDnsClient->putZoneMetadata($canonicalZoneId, 'ALLOW-AXFR-FROM', $payload->allowAxfrFrom);
            $this->powerDnsClient->putZoneMetadata($canonicalZoneId, 'ALSO-NOTIFY', $payload->alsoNotify);
        } else {
            // Clear outbound primary transfer metadata when switching to Native or Secondary
            $this->powerDnsClient->putZoneMetadata($canonicalZoneId, 'ALLOW-AXFR-FROM', []);
            $this->powerDnsClient->putZoneMetadata($canonicalZoneId, 'ALSO-NOTIFY', []);
        }

        // 3. When transitioning from Secondary -> Primary/Native, trigger a zone rectify
        $rectified = false;
        if ($previousKind === 'Secondary' && in_array($payload->kind, ['Native', 'Primary'], true)) {
            $this->powerDnsClient->rectifyZone($canonicalZoneId);
            $rectified = true;
        }

        $auditDetails = [
            'zone' => $canonicalZoneId,
            'previous_kind' => $previousKind,
            'kind' => $payload->kind,
            'masters' => $payload->masters,
            'allow_axfr_from' => $payload->kind === 'Primary' ? $payload->allowAxfrFrom : [],
            'also_notify' => $payload->kind === 'Primary' ? $payload->alsoNotify : [],
            'rectified' => $rectified,
        ];

        Log::info('PowerDNS zone operating mode updated', [
            'actor_id' => $actor?->id,
            'actor_username' => $actor?->username,
            'zone' => $canonicalZoneId,
            'previous_kind' => $previousKind,
            'mode' => $payload->kind,
            'masters' => $payload->masters,
            'allow_axfr_from' => $auditDetails['allow_axfr_from'],
            'also_notify' => $auditDetails['also_notify'],
            'rectified' => $rectified,
            'status' => 'success',
        ]);

        ($this->auditLogger ?? app(AuditLoggerInterface::class))->log([
            'user_id' => $actor?->id,
            'action' => 'pdns_update_zone_mode',
            'command' => 'dns.zones.mode.update',
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
