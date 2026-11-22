<?php

declare(strict_types=1);

namespace App\Domains\Dns\Actions;

use App\Domains\Dns\Models\PdnsSetting;
use App\Domains\Dns\ValueObjects\DefaultNameserverEntry;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use Illuminate\Support\Facades\Log;

final class UpdateDefaultNameserversAction
{
    public function __construct(
        private readonly ?AuditLoggerInterface $auditLogger = null,
    ) {}

    /**
     * @param  list<DefaultNameserverEntry>  $entries
     */
    public function handle(
        array $entries,
        ?User $actor = null,
        ?string $ipAddress = null,
    ): PdnsSetting {
        $setting = PdnsSetting::current();

        $storageArray = array_values(array_map(
            static fn (DefaultNameserverEntry $entry): array => $entry->toStorageArray(),
            $entries
        ));

        $setting->update([
            'default_nameservers' => $storageArray,
        ]);

        Log::info('PowerDNS default nameservers updated', [
            'actor' => $actor?->username ?? 'system',
            'actor_id' => $actor?->id,
            'actor_username' => $actor?->username,
            'action' => 'pdns_update_default_nameservers',
            'zone' => null,
            'mode' => 'nameservers',
            'default_nameservers' => $storageArray,
            'status' => 'success',
        ]);

        ($this->auditLogger ?? app(AuditLoggerInterface::class))->log([
            'user_id' => $actor?->id,
            'action' => 'pdns_update_default_nameservers',
            'command' => 'dns.nameservers.update',
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => json_encode([
                'default_nameservers' => $storageArray,
            ], JSON_THROW_ON_ERROR),
        ]);

        return $setting->refresh();
    }
}
