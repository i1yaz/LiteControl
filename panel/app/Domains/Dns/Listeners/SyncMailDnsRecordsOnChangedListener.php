<?php

declare(strict_types=1);

namespace App\Domains\Dns\Listeners;

use App\Domains\Shared\Contracts\ModuleRegistryQueryInterface;
use App\Domains\Shared\Events\MailDnsRecordsChanged;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

final class SyncMailDnsRecordsOnChangedListener implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(
        private readonly ModuleRegistryQueryInterface $moduleRegistry,
    ) {}

    public function handle(MailDnsRecordsChanged $event): void
    {
        if (! $this->moduleRegistry->isActive('dns')) {
            return;
        }

        Log::info('Syncing mail DNS records to active PowerDNS module', [
            'domain' => $event->domain,
            'record_count' => count($event->records),
        ]);
    }
}
