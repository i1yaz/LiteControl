<?php

declare(strict_types=1);

namespace App\Domains\Dns\Actions;

use App\Domains\Dns\Models\DnsZoneTemplate;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class SetDefaultDnsZoneTemplateAction
{
    public function __construct(
        private readonly ?AuditLoggerInterface $auditLogger = null,
    ) {}

    public function handle(
        DnsZoneTemplate $template,
        ?User $actor = null,
        ?string $ipAddress = null,
    ): DnsZoneTemplate {
        DB::transaction(function () use ($template): void {
            DnsZoneTemplate::query()->update(['is_default' => false]);
            $template->update(['is_default' => true]);
        });

        Log::info('PowerDNS default zone template updated', [
            'actor' => $actor?->username ?? 'system',
            'actor_id' => $actor?->id,
            'actor_username' => $actor?->username,
            'action' => 'pdns_set_default_zone_template',
            'zone' => $template->name,
            'mode' => 'template',
            'template_id' => $template->id,
            'template_name' => $template->name,
            'status' => 'success',
        ]);

        ($this->auditLogger ?? app(AuditLoggerInterface::class))->log([
            'user_id' => $actor?->id,
            'action' => 'pdns_set_default_zone_template',
            'command' => 'dns.templates.default',
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => json_encode([
                'template_id' => $template->id,
                'name' => $template->name,
            ], JSON_THROW_ON_ERROR),
        ]);

        return $template->refresh();
    }
}
