<?php

declare(strict_types=1);

namespace App\Domains\Mail\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Mail\Contracts\MailServerDriverInterface;
use App\Domains\Mail\Jobs\PurgeMailboxJob;
use App\Domains\Mail\Models\MailAccount;
use App\Domains\Mail\Models\MailAlias;
use App\Domains\Mail\Models\MailPendingPurge;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use Illuminate\Support\Facades\DB;

final class DeleteMailAccountAction
{
    public function __construct(
        private readonly MailServerDriverInterface $mailDriver,
        private readonly AuditLoggerInterface $auditLogger,
    ) {}

    public function execute(User $actor, MailAccount $account): MailPendingPurge
    {
        $email = $account->email;
        $localPart = $account->local_part;
        $domain = $account->mailDomain->domain;
        $accountId = $account->id;

        $purge = DB::transaction(function () use ($actor, $account, $accountId, $email, $localPart, $domain): MailPendingPurge {
            // Clean dependent local aliases pointing to this mailbox
            MailAlias::where('destination_email', $email)->delete();

            $account->delete();

            $tombstone = MailPendingPurge::create([
                'entity_type' => 'mailbox',
                'domain' => $domain,
                'local_part' => $localPart,
                'email' => $email,
                'status' => 'pending',
                'attempts' => 0,
            ]);

            $this->auditLogger->log([
                'user_id' => $actor->id,
                'action' => 'mail.account.deleted',
                'target_type' => MailAccount::class,
                'target_id' => $accountId,
                'metadata' => [
                    'email' => $email,
                    'purge_id' => $tombstone->id,
                ],
            ]);

            return $tombstone;
        });

        $this->mailDriver->evictSession($email);
        PurgeMailboxJob::dispatch($purge->id, $domain, $localPart);

        return $purge;
    }
}
