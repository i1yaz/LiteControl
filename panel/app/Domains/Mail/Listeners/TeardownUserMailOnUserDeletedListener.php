<?php

declare(strict_types=1);

namespace App\Domains\Mail\Listeners;

use App\Domains\Identity\Events\UserDeleted;
use App\Domains\Mail\Contracts\MailServerDriverInterface;
use App\Domains\Mail\Jobs\PurgeMailDomainJob;
use App\Domains\Mail\Jobs\ReconcileMailServerJob;
use App\Domains\Mail\Models\MailAccount;
use App\Domains\Mail\Models\MailAlias;
use App\Domains\Mail\Models\MailDomain;
use App\Domains\Mail\Models\MailPendingPurge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TeardownUserMailOnUserDeletedListener
{
    public function __construct(
        private readonly MailServerDriverInterface $mailDriver,
    ) {}

    public function handle(UserDeleted $event): void
    {
        $mailDomains = MailDomain::with('accounts')
            ->where('user_id', $event->userId)
            ->get();

        if ($mailDomains->isEmpty()) {
            return;
        }

        $emailsToEvict = [];

        foreach ($mailDomains as $mailDomain) {
            foreach ($mailDomain->accounts as $account) {
                $emailsToEvict[] = $account->email;
            }

            MailAlias::where('mail_domain_id', $mailDomain->id)->delete();
            MailAccount::where('mail_domain_id', $mailDomain->id)->delete();
            DB::table('mail_dkim_keys')->where('mail_domain_id', $mailDomain->id)->delete();

            $domainName = $mailDomain->domain;
            $mailDomain->delete();

            $tombstone = MailPendingPurge::create([
                'entity_type' => 'domain',
                'domain' => $domainName,
                'local_part' => null,
                'email' => null,
                'status' => 'pending',
                'attempts' => 0,
            ]);

            PurgeMailDomainJob::dispatch($tombstone->id, $domainName);
        }

        ReconcileMailServerJob::dispatch('all');

        if ($emailsToEvict !== []) {
            DB::afterCommit(function () use ($emailsToEvict): void {
                foreach ($emailsToEvict as $email) {
                    try {
                        $this->mailDriver->evictSession($email);
                    } catch (\Throwable $e) {
                        Log::warning('Failed to evict mail session during user teardown', [
                            'email' => $email,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
        }
    }
}
