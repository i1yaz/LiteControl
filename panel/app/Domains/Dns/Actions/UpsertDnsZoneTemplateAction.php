<?php

declare(strict_types=1);

namespace App\Domains\Dns\Actions;

use App\Domains\Dns\Models\DnsZoneTemplate;
use App\Domains\Dns\ValueObjects\UpsertZoneTemplatePayload;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class UpsertDnsZoneTemplateAction
{
    public function __construct(
        private readonly ?AuditLoggerInterface $auditLogger = null,
    ) {}

    public function handle(
        UpsertZoneTemplatePayload $payload,
        ?DnsZoneTemplate $existing = null,
        ?User $actor = null,
        ?string $ipAddress = null,
    ): DnsZoneTemplate {
        /** @var DnsZoneTemplate $template */
        $template = DB::transaction(function () use ($payload, $existing): DnsZoneTemplate {
            $shouldBeDefault = $payload->isDefault
                || ! DnsZoneTemplate::query()
                    ->when($existing !== null, static fn ($q) => $q->where('id', '!=', $existing->id))
                    ->where('is_default', true)
                    ->exists();

            if ($shouldBeDefault) {
                DnsZoneTemplate::query()->update(['is_default' => false]);
            }

            if ($existing === null) {
                $template = DnsZoneTemplate::query()->create([
                    'name' => $payload->name,
                    'description' => $payload->description !== '' ? $payload->description : null,
                    'is_default' => $shouldBeDefault,
                ]);
            } else {
                $existing->update([
                    'name' => $payload->name,
                    'description' => $payload->description !== '' ? $payload->description : null,
                    'is_default' => $shouldBeDefault,
                ]);
                $existing->records()->delete();
                $template = $existing;
            }

            foreach ($payload->records as $record) {
                $template->records()->create($record->toDatabaseArray());
            }

            return $template->load('records');
        });

        $actionKey = $existing === null ? 'pdns_create_zone_template' : 'pdns_update_zone_template';

        Log::info('PowerDNS zone template saved', [
            'actor' => $actor?->username ?? 'system',
            'actor_id' => $actor?->id,
            'actor_username' => $actor?->username,
            'action' => $actionKey,
            'zone' => $template->name,
            'mode' => 'template',
            'template_id' => $template->id,
            'template_name' => $template->name,
            'is_default' => $template->is_default,
            'records_count' => count($payload->records),
            'status' => 'success',
        ]);

        ($this->auditLogger ?? app(AuditLoggerInterface::class))->log([
            'user_id' => $actor?->id,
            'action' => $actionKey,
            'command' => $existing === null ? 'dns.templates.store' : 'dns.templates.update',
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => json_encode([
                'template_id' => $template->id,
                'name' => $template->name,
                'is_default' => $template->is_default,
                'records_count' => count($payload->records),
            ], JSON_THROW_ON_ERROR),
        ]);

        return $template;
    }
}
