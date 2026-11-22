<?php

declare(strict_types=1);

namespace App\Domains\Mail\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Mail\Jobs\ReconcileMailServerJob;
use App\Domains\Mail\Models\MailDomain;
use App\Domains\Mail\ValueObjects\SpamPolicy;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use App\Domains\Shared\Contracts\ModuleRegistryQueryInterface;
use Illuminate\Support\Facades\DB;

final class UpdateDomainSpamPolicyAction
{
    public function __construct(
        private readonly AuditLoggerInterface $auditLogger,
        private readonly ModuleRegistryQueryInterface $moduleRegistry,
    ) {}

    public function execute(
        User $actor,
        MailDomain $mailDomain,
        SpamPolicy $policy,
    ): MailDomain {
        $updated = DB::transaction(function () use ($actor, $mailDomain, $policy): MailDomain {
            $mailDomain->update([
                'spam_filter_enabled' => $policy->enabled,
                'spam_header_score' => $policy->headerScore,
                'spam_reject_score' => $policy->rejectScore,
            ]);

            $this->auditLogger->log([
                'user_id' => $actor->id,
                'action' => 'mail.spam_policy.updated',
                'target_type' => MailDomain::class,
                'target_id' => $mailDomain->id,
                'metadata' => [
                    'domain' => $mailDomain->domain,
                    'spam_filter_enabled' => $policy->enabled,
                    'spam_header_score' => $policy->headerScore,
                    'spam_reject_score' => $policy->rejectScore,
                ],
            ]);

            return $mailDomain->refresh();
        });

        if ($this->moduleRegistry->isActive('mail_filter')) {
            ReconcileMailServerJob::dispatch('filter');
        }

        return $updated;
    }
}
