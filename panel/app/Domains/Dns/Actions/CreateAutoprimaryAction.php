<?php

declare(strict_types=1);

namespace App\Domains\Dns\Actions;

use App\Domains\Dns\Contracts\PowerDnsClientInterface;
use App\Domains\Dns\Exceptions\PowerDnsException;
use App\Domains\Dns\Models\PdnsSetting;
use App\Domains\Dns\ValueObjects\AutoprimaryEntry;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use Illuminate\Support\Facades\Log;

final class CreateAutoprimaryAction
{
    public function __construct(
        private readonly PowerDnsClientInterface $powerDnsClient,
        private readonly ?AuditLoggerInterface $auditLogger = null,
    ) {}

    /**
     * Add a trusted Autoprimary (Supermaster) via `POST /api/v1/servers/localhost/autoprimaries`.
     *
     * @return array{
     *     ip: string,
     *     nameserver: string,
     *     account: string,
     *     autosecondary_enabled: bool,
     *     secondary_enabled: bool
     * }
     *
     * @throws PowerDnsException
     */
    public function handle(
        AutoprimaryEntry $entry,
        ?User $actor = null,
        ?string $ipAddress = null,
    ): array {
        $this->powerDnsClient->createAutoprimary($entry->toPowerDnsPayload());

        $setting = PdnsSetting::current();
        $auditDetails = [
            'ip' => $entry->ip,
            'nameserver' => $entry->nameserver,
            'account' => $entry->account,
            'autosecondary_enabled' => (bool) $setting->autosecondary_enabled,
            'secondary_enabled' => (bool) $setting->secondary_enabled,
        ];

        Log::info('PowerDNS autoprimary added', [
            'actor' => $actor?->username ?? 'system',
            'actor_id' => $actor?->id,
            'actor_username' => $actor?->username,
            'action' => 'pdns_create_autoprimary',
            'zone' => $entry->nameserver,
            'mode' => 'Autoprimary',
            'ip' => $entry->ip,
            'nameserver' => $entry->nameserver,
            'account' => $entry->account,
            'status' => 'success',
        ]);

        ($this->auditLogger ?? app(AuditLoggerInterface::class))->log([
            'user_id' => $actor?->id,
            'action' => 'pdns_create_autoprimary',
            'command' => 'dns.autoprimaries.store',
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => json_encode($auditDetails, JSON_THROW_ON_ERROR),
        ]);

        return $auditDetails;
    }
}
