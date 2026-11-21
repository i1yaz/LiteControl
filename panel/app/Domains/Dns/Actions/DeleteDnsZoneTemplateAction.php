<?php

declare(strict_types=1);

namespace App\Domains\Dns\Actions;

use App\Domains\Dns\Models\DnsZoneTemplate;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class DeleteDnsZoneTemplateAction
{
    public function __construct(
        private readonly ?AuditLoggerInterface $auditLogger = null,
    ) {}

    public function handle(
        DnsZoneTemplate $template,
        ?User $actor = null,
        ?string $ipAddress = null,
    ): void {
        $templateId = $template->id;
        $templateName = (string) $template->name;
        $wasDefault = (bool) $template->is_default;

        DB::transaction(function () use ($template, $wasDefault): void {
            $template->delete();

            if ($wasDefault) {
                $replacement = DnsZoneTemplate::query()->orderBy('id')->first();
                $replacement?->update(['is_default' => true]);
            }
        });

        Log::info('PowerDNS zone template deleted', [
            'actor_id' => $actor?->id,
            'actor_username' => $actor?->username,
            'template_id' => $templateId,
            'template_name' => $templateName,
        ]);

        ($this->auditLogger ?? app(AuditLoggerInterface::class))->log([
            'user_id' => $actor?->id,
            'action' => 'pdns_delete_zone_template',
            'command' => 'dns.templates.destroy',
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => json_encode([
                'template_id' => $templateId,
                'name' => $templateName,
            ], JSON_THROW_ON_ERROR),
        ]);
    }
}
