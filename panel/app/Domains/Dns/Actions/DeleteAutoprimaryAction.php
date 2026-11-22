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

final class DeleteAutoprimaryAction
{
    public function __construct(
        private readonly PowerDnsClientInterface $powerDnsClient,
        private readonly ZoneNameNormalizer $normalizer = new ZoneNameNormalizer,
        private readonly ?AuditLoggerInterface $auditLogger = null,
    ) {}

    /**
     * Delete a trusted Autoprimary (Supermaster) via `DELETE /api/v1/servers/localhost/autoprimaries/{ip}/{nameserver}`.
     *
     * @return array{ip: string, nameserver: string}
     *
     * @throws InvalidArgumentException
     * @throws PowerDnsException
     */
    public function handle(
        string $ip,
        string $nameserver,
        ?User $actor = null,
        ?string $ipAddress = null,
    ): array {
        $trimmedIp = trim($ip);
        if ($trimmedIp === '' || filter_var($trimmedIp, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException("Autoprimary IP address \"{$trimmedIp}\" is not valid.");
        }

        $trimmedNs = trim($nameserver);
        if ($trimmedNs === '') {
            throw new InvalidArgumentException('Autoprimary nameserver is required.');
        }

        $canonicalNs = $this->normalizer->ensureTrailingDot($trimmedNs);

        try {
            $this->powerDnsClient->deleteAutoprimary($trimmedIp, $canonicalNs);
        } catch (PowerDnsException $e) {
            // Some legacy autoprimary rows may have been stored without a trailing dot
            if ($e->getStatusCode() === 404 && $trimmedNs !== $canonicalNs) {
                $this->powerDnsClient->deleteAutoprimary($trimmedIp, rtrim($canonicalNs, '.'));
            } else {
                throw $e;
            }
        }

        $auditDetails = [
            'ip' => $trimmedIp,
            'nameserver' => $canonicalNs,
        ];

        Log::info('PowerDNS autoprimary deleted', [
            'actor' => $actor?->username ?? 'system',
            'actor_id' => $actor?->id,
            'actor_username' => $actor?->username,
            'action' => 'pdns_delete_autoprimary',
            'zone' => $canonicalNs,
            'mode' => 'Autoprimary',
            'ip' => $trimmedIp,
            'nameserver' => $canonicalNs,
            'status' => 'success',
        ]);

        ($this->auditLogger ?? app(AuditLoggerInterface::class))->log([
            'user_id' => $actor?->id,
            'action' => 'pdns_delete_autoprimary',
            'command' => 'dns.autoprimaries.destroy',
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => json_encode($auditDetails, JSON_THROW_ON_ERROR),
        ]);

        return $auditDetails;
    }
}
