<?php

declare(strict_types=1);

namespace App\Domains\Mail\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Mail\Contracts\MailServerDriverInterface;
use App\Domains\Mail\Jobs\PurgeMailDomainJob;
use App\Domains\Mail\Jobs\ReconcileMailServerJob;
use App\Domains\Mail\Models\MailAccount;
use App\Domains\Mail\Models\MailAlias;
use App\Domains\Mail\Models\MailDkimKey;
use App\Domains\Mail\Models\MailDomain;
use App\Domains\Mail\Models\MailPendingPurge;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use App\Domains\Shared\Events\MailDnsRecordsChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class DeleteMailDomainAction
{
    public function __construct(
        private readonly MailServerDriverInterface $mailDriver,
        private readonly AuditLoggerInterface $auditLogger,
    ) {}

    public function execute(User $actor, MailDomain $mailDomain, bool $deleteSsl = true): void
    {
        $domainName = $mailDomain->domain;
        $mailHost = $mailDomain->mailHost();
        $effectiveDeleteSsl = $mailDomain->uses_shared_mail_host ? false : $deleteSsl;
        $domainId = $mailDomain->id;
        $emailsToEvict = [];

        $tombstone = DB::transaction(function () use ($actor, $mailDomain, $domainName, $mailHost, $domainId, $effectiveDeleteSsl, &$emailsToEvict): MailPendingPurge {
            $emailsToEvict = MailAccount::query()
                ->where('mail_domain_id', $domainId)
                ->pluck('email')
                ->all();

            MailAlias::where('mail_domain_id', $domainId)->delete();
            MailAccount::where('mail_domain_id', $domainId)->delete();
            MailDkimKey::where('mail_domain_id', $domainId)->delete();
            $mailDomain->delete();

            $purge = MailPendingPurge::create([
                'entity_type' => 'domain',
                'domain' => $domainName,
                'local_part' => null,
                'email' => null,
                'status' => 'pending',
                'attempts' => 0,
            ]);

            $this->auditLogger->log([
                'user_id' => $actor->id,
                'action' => 'mail.domain.deleted',
                'target_type' => MailDomain::class,
                'target_id' => $domainId,
                'metadata' => [
                    'domain' => $domainName,
                    'mail_hostname' => $mailHost,
                    'accounts_deleted' => count($emailsToEvict),
                    'delete_ssl' => $effectiveDeleteSsl,
                ],
            ]);

            return $purge;
        });

        PurgeMailDomainJob::dispatch($tombstone->id, $domainName, deleteSsl: $effectiveDeleteSsl, sslHostname: $mailHost);
        ReconcileMailServerJob::dispatch('all');
        MailDnsRecordsChanged::dispatch($domainName, []);

        if ($emailsToEvict !== []) {
            DB::afterCommit(function () use ($emailsToEvict): void {
                foreach ($emailsToEvict as $email) {
                    try {
                        $this->mailDriver->evictSession($email);
                    } catch (\Throwable $e) {
                        Log::warning('Failed to evict mail session during mail domain deletion', [
                            'email' => $email,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
        }
    }
}
