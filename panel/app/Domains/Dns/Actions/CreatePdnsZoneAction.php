<?php

declare(strict_types=1);

namespace App\Domains\Dns\Actions;

use App\Domains\Dns\Contracts\PowerDnsClientInterface;
use App\Domains\Dns\Exceptions\PowerDnsException;
use App\Domains\Dns\Models\DnsZoneTemplate;
use App\Domains\Dns\ValueObjects\CreatePdnsZonePayload;
use App\Domains\Dns\ValueObjects\ZoneTemplateVariables;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

final class CreatePdnsZoneAction
{
    public function __construct(
        private readonly PowerDnsClientInterface $powerDnsClient,
        private readonly ?AuditLoggerInterface $auditLogger = null,
        private readonly RenderZoneTemplateAction $renderZoneTemplateAction = new RenderZoneTemplateAction,
    ) {}

    /**
     * Create a forward or reverse DNS zone in PowerDNS with automatic SOA-EDIT-API=DEFAULT and API-RECTIFY=1,
     * optionally rendering and applying initial RRSets from a Zone Template.
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
        $template = null;
        $renderedRrsets = [];

        if ($payload->templateId !== null && $payload->kind !== 'Secondary' && $payload->zoneType === 'forward') {
            $template = DnsZoneTemplate::query()->with('records')->find($payload->templateId);
            if ($template === null) {
                throw new InvalidArgumentException("Selected DNS zone template #{$payload->templateId} was not found.");
            }

            $variables = new ZoneTemplateVariables(
                domain: $payload->name,
                ipv4: (string) ($payload->templateIpv4 ?? ''),
                ipv6: (string) ($payload->templateIpv6 ?? ''),
                ns1: $payload->nameservers[0] ?? ('ns1.'.$payload->name),
                ns2: $payload->nameservers[1] ?? ($payload->nameservers[0] ?? ('ns2.'.$payload->name)),
            );

            $renderedRrsets = $this->renderZoneTemplateAction->handle($template, $variables);
        }

        $apiPayload = $payload->toPowerDnsPayload();
        $createdZone = $this->powerDnsClient->createZone($apiPayload);

        if ($renderedRrsets !== []) {
            $this->powerDnsClient->patchRrsets($payload->name, $renderedRrsets);
        }

        $auditDetails = [
            'zone' => $payload->name,
            'kind' => $payload->kind,
            'zone_type' => $payload->zoneType,
            'nameservers' => $payload->nameservers,
            'masters' => $payload->masters,
            'soa_edit_api' => $payload->soaEditApi,
            'api_rectify' => $payload->apiRectify,
            'template_id' => $template?->id,
            'template_name' => $template?->name,
            'template_rrsets_count' => count($renderedRrsets),
        ];

        Log::info('PowerDNS zone created', [
            'actor_id' => $actor?->id,
            'actor_username' => $actor?->username,
            'zone' => $payload->name,
            'kind' => $payload->kind,
            'zone_type' => $payload->zoneType,
            'template_id' => $template?->id,
            'template_rrsets_count' => count($renderedRrsets),
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
