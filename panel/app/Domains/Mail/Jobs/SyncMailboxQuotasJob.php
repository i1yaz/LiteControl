<?php

declare(strict_types=1);

namespace App\Domains\Mail\Jobs;

use App\Domains\Mail\Contracts\MailServerDriverInterface;
use App\Domains\Mail\Models\MailAccount;
use App\Domains\Mail\Models\MailDomain;
use App\Domains\Shared\Contracts\ModuleRegistryQueryInterface;
use App\Exceptions\System\LcCliException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncMailboxQuotasJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly ?string $domain = null,
    ) {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'mail_sync_quotas_'.($this->domain ?? 'all');
    }

    public function handle(
        MailServerDriverInterface $mailDriver,
        ModuleRegistryQueryInterface $moduleRegistry,
    ): void {
        if (! $moduleRegistry->isActive('mail_core')) {
            return;
        }

        $query = MailDomain::query()->where('active', true);

        if ($this->domain !== null) {
            $query->where('domain', $this->domain);
        }

        foreach ($query->cursor() as $mailDomain) {
            try {
                $usage = $mailDriver->readQuotaUsage($mailDomain->domain);

                foreach ($usage as $email => $usedBytes) {
                    MailAccount::where('mail_domain_id', $mailDomain->id)
                        ->where('email', $email)
                        ->update(['used_bytes' => max(0, (int) $usedBytes)]);
                }
            } catch (LcCliException $e) {
                Log::warning('Failed to sync mailbox quotas for domain', [
                    'domain' => $mailDomain->domain,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
