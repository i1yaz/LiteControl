<?php

declare(strict_types=1);

namespace App\Domains\Mail\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Mail\Jobs\ReconcileMailServerJob;
use App\Domains\Mail\Models\MailDomain;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use Illuminate\Support\Facades\DB;

final class UpdateMailDomainAction
{
    public function __construct(
        private readonly AuditLoggerInterface $auditLogger,
    ) {}

    public function execute(
        User $actor,
        MailDomain $mailDomain,
        int $defaultQuotaMb,
        int $maxAccounts,
        int $outboundHourlyLimit,
        bool $active,
    ): MailDomain {
        $updated = DB::transaction(function () use ($actor, $mailDomain, $defaultQuotaMb, $maxAccounts, $outboundHourlyLimit, $active): MailDomain {
            $mailDomain->update([
                'default_quota_mb' => $defaultQuotaMb,
                'max_accounts' => $maxAccounts,
                'outbound_hourly_limit' => $outboundHourlyLimit,
                'active' => $active,
            ]);

            $this->auditLogger->log([
                'user_id' => $actor->id,
                'action' => 'mail.domain.updated',
                'target_type' => MailDomain::class,
                'target_id' => $mailDomain->id,
                'metadata' => [
                    'domain' => $mailDomain->domain,
                    'default_quota_mb' => $defaultQuotaMb,
                    'max_accounts' => $maxAccounts,
                    'outbound_hourly_limit' => $outboundHourlyLimit,
                    'active' => $active,
                ],
            ]);

            return $mailDomain->refresh();
        });

        ReconcileMailServerJob::dispatch('all');

        return $updated;
    }
}
