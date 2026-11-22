<?php

declare(strict_types=1);

namespace App\Domains\Dns\Actions;

use App\Domains\Dns\Contracts\PowerDnsClientInterface;
use App\Domains\Dns\Exceptions\PowerDnsException;
use App\Domains\Dns\ValueObjects\ZoneNameNormalizer;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use Illuminate\Support\Facades\Log;

final class ExportPdnsZoneAction
{
    public function __construct(
        private readonly PowerDnsClientInterface $powerDnsClient,
        private readonly ZoneNameNormalizer $normalizer,
        private readonly ?AuditLoggerInterface $auditLogger = null,
    ) {}

    /**
     * Export a zone in BIND/AXFR format (`GET /api/v1/servers/localhost/zones/{zone_id}/export`).
     *
     * @return array{zone_id: string, filename: string, content: string}
     *
     * @throws PowerDnsException
     */
    public function handle(
        string $zoneId,
        ?User $actor = null,
        ?string $ipAddress = null,
    ): array {
        $canonicalZoneId = $this->normalizer->ensureTrailingDot($zoneId);
        $content = $this->powerDnsClient->exportZone($canonicalZoneId);

        $safeBase = preg_replace('/[^a-zA-Z0-9._-]/', '_', rtrim($canonicalZoneId, '.')) ?: 'zone';
        $filename = $safeBase.'.zone';

        Log::info('PowerDNS zone exported', [
            'actor' => $actor?->username ?? 'system',
            'actor_id' => $actor?->id,
            'actor_username' => $actor?->username,
            'action' => 'pdns_export_zone',
            'zone' => $canonicalZoneId,
            'mode' => 'export',
            'status' => 'success',
        ]);

        ($this->auditLogger ?? app(AuditLoggerInterface::class))->log([
            'user_id' => $actor?->id,
            'action' => 'pdns_export_zone',
            'command' => 'dns.zones.export',
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => json_encode([
                'zone' => $canonicalZoneId,
                'filename' => $filename,
            ], JSON_THROW_ON_ERROR),
        ]);

        return [
            'zone_id' => $canonicalZoneId,
            'filename' => $filename,
            'content' => $content,
        ];
    }
}
