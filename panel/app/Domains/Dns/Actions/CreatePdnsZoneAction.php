<?php

declare(strict_types=1);

namespace App\Domains\Dns\Actions;

use App\Domains\Dns\Contracts\PowerDnsClientInterface;
use App\Domains\Dns\Exceptions\PowerDnsException;
use App\Domains\Dns\ValueObjects\CreatePdnsZonePayload;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use Illuminate\Support\Facades\Log;

final class CreatePdnsZoneAction
{
    public function __construct(
        private readonly PowerDnsClientInterface $powerDnsClient,
        private readonly ?AuditLoggerInterface $auditLogger = null,
    ) {}

    /**
     * Create a forward or reverse DNS zone in PowerDNS with automatic SOA-EDIT-API=DEFAULT and API-RECTIFY=1.
     *
     * @return array<string, mixed>
     *
     * @throws PowerDnsException
     */
    public function handle(
        CreatePdnsZonePayload $payload,
        ?User $actor = null,
        ?string $ipAddress = null,
    ): array {
        $apiPayload = $payload->toPowerDnsPayload();

        $createdZone = $this->powerDnsClient->createZone($apiPayload);

        $auditDetails = [
            'zone' => $payload->name,
            'kind' => $payload->kind,
            'zone_type' => $payload->zoneType,
            'nameservers' => $payload->nameservers,
            'masters' => $payload->masters,
            'soa_edit_api' => $payload->soaEditApi,
            'api_rectify' => $payload->apiRectify,
        ];

        Log::info('PowerDNS zone created', [
            'actor_id' => $actor?->id,
            'actor_username' => $actor?->username,
            'zone' => $payload->name,
            'kind' => $payload->kind,
            'zone_type' => $payload->zoneType,
        ]);

        ($this->auditLogger ?? app(AuditLoggerInterface::class))->log([
            'user_id' => $actor?->id,
            'action' => 'pdns_create_zone',
            'command' => 'dns.zones.store',
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => json_encode($auditDetails, JSON_THROW_ON_ERROR),
        ]);

        return $createdZone;
    }
}
