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

final class FlushPdnsCacheAction
{
    public function __construct(
        private readonly PowerDnsClientInterface $powerDnsClient,
        private readonly ZoneNameNormalizer $zoneNameNormalizer = new ZoneNameNormalizer,
        private readonly ?AuditLoggerInterface $auditLogger = null,
    ) {}

    /**
     * Flush the PowerDNS authoritative query/packet cache for a specific zone or globally (`domain=.`).
     *
     * @return array{domain: string, scope: string, count: int, result: string}
     *
     * @throws InvalidArgumentException
     * @throws PowerDnsException
     */
    public function handle(
        ?string $domain = null,
        ?User $actor = null,
        ?string $ipAddress = null,
    ): array {
        $trimmed = trim((string) $domain);
        $isGlobal = $trimmed === '' || $trimmed === '.';

        $canonicalDomain = $isGlobal
            ? '.'
            : $this->zoneNameNormalizer->ensureTrailingDot($trimmed);

        $scope = $isGlobal ? 'global' : 'zone';

        $apiResponse = $this->powerDnsClient->flushCache($canonicalDomain);

        $flushedCount = isset($apiResponse['count']) && is_numeric($apiResponse['count'])
            ? max(0, (int) $apiResponse['count'])
            : 0;

        $resultMessage = isset($apiResponse['result']) && is_string($apiResponse['result']) && trim($apiResponse['result']) !== ''
            ? trim($apiResponse['result'])
            : 'Flushed cache.';

        $auditDetails = [
            'domain' => $canonicalDomain,
            'scope' => $scope,
            'count' => $flushedCount,
            'result' => $resultMessage,
        ];

        Log::info('PowerDNS authoritative cache flushed', [
            'actor' => $actor?->username ?? 'system',
            'actor_id' => $actor?->id,
            'actor_username' => $actor?->username,
            'action' => 'pdns_flush_cache',
            'zone' => $canonicalDomain,
            'mode' => $scope,
            'count' => $flushedCount,
            'result' => $resultMessage,
            'status' => 'success',
        ]);

        ($this->auditLogger ?? app(AuditLoggerInterface::class))->log([
            'user_id' => $actor?->id,
            'action' => 'pdns_flush_cache',
            'command' => $isGlobal ? 'dns.settings.cache.flush' : 'dns.zones.cache.flush',
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => json_encode($auditDetails, JSON_THROW_ON_ERROR),
        ]);

        return $auditDetails;
    }
}
