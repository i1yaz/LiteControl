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

final class DeletePdnsZoneAction
{
    public function __construct(
        private readonly PowerDnsClientInterface $powerDnsClient,
        private readonly ZoneNameNormalizer $normalizer,
        private readonly ?AuditLoggerInterface $auditLogger = null,
    ) {}

    /**
     * Delete a PowerDNS zone after verifying the typed confirmation name; log actor, zone name, and timestamp.
     *
     * @throws InvalidArgumentException
     * @throws PowerDnsException
     */
    public function handle(
        string $zoneId,
        string $confirmName,
        ?User $actor = null,
        ?string $ipAddress = null,
    ): void {
        $canonicalZoneId = $this->normalizer->ensureTrailingDot($zoneId);
        $displayUtf8 = $this->normalizer->toDisplayUtf8($canonicalZoneId);

        $normalizedTarget = strtolower(rtrim($canonicalZoneId, '.'));
        $normalizedConfirm = strtolower(rtrim(trim($confirmName), '.'));
        $normalizedDisplay = strtolower(rtrim($displayUtf8, '.'));

        if ($normalizedConfirm === '' || ($normalizedConfirm !== $normalizedTarget && $normalizedConfirm !== $normalizedDisplay)) {
            throw new InvalidArgumentException(
                sprintf('Zone deletion confirmation "%s" does not match "%s".', $confirmName, rtrim($canonicalZoneId, '.'))
            );
        }

        $this->powerDnsClient->deleteZone($canonicalZoneId);

        $timestamp = now()->toIso8601String();
        $auditDetails = [
            'actor_id' => $actor?->id,
            'actor_username' => $actor?->username,
            'zone' => $canonicalZoneId,
            'timestamp' => $timestamp,
        ];

        Log::info('PowerDNS zone deleted', $auditDetails);

        ($this->auditLogger ?? app(AuditLoggerInterface::class))->log([
            'user_id' => $actor?->id,
            'action' => 'pdns_delete_zone',
            'command' => 'dns.zones.destroy',
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => json_encode($auditDetails, JSON_THROW_ON_ERROR),
        ]);
    }
}
