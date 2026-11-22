<?php

declare(strict_types=1);

namespace App\Domains\Mail\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Mail\Contracts\MailServerDriverInterface;
use App\Domains\Mail\Models\MailAccount;
use App\Domains\Mail\Models\MailDomain;
use App\Domains\Mail\ValueObjects\MailQuotaMb;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateMailAccountAction
{
    public function __construct(
        private readonly MailServerDriverInterface $mailDriver,
        private readonly AuditLoggerInterface $auditLogger,
    ) {}

    public function execute(
        User $actor,
        MailAccount $account,
        ?string $password = null,
        ?MailQuotaMb $quotaMb = null,
        ?bool $active = null,
        ?bool $allowImap = null,
        ?bool $allowPop3 = null,
        ?bool $allowSmtp = null,
    ): MailAccount {
        if ($quotaMb !== null && $quotaMb->value !== $account->quota_mb) {
            $this->assertQuotaCeiling($account, $quotaMb);
        }

        $passwordChanged = $password !== null && $password !== '';
        $flagsTightened = ($active === false && $account->active)
            || ($allowImap === false && $account->allow_imap)
            || ($allowPop3 === false && $account->allow_pop3)
            || ($allowSmtp === false && $account->allow_smtp);

        $updates = [];
        if ($passwordChanged) {
            $updates['password_hash'] = CreateMailAccountAction::hashPassword($password);
        }
        if ($quotaMb !== null) {
            $updates['quota_mb'] = $quotaMb->value;
        }
        if ($active !== null) {
            $updates['active'] = $active;
        }
        if ($allowImap !== null) {
            $updates['allow_imap'] = $allowImap;
        }
        if ($allowPop3 !== null) {
            $updates['allow_pop3'] = $allowPop3;
        }
        if ($allowSmtp !== null) {
            $updates['allow_smtp'] = $allowSmtp;
        }

        $updated = DB::transaction(function () use ($actor, $account, $updates, $passwordChanged): MailAccount {
            if ($updates !== []) {
                $account->update($updates);
            }

            $this->auditLogger->log([
                'user_id' => $actor->id,
                'action' => 'mail.account.updated',
                'target_type' => MailAccount::class,
                'target_id' => $account->id,
                'metadata' => [
                    'email' => $account->email,
                    'password_changed' => $passwordChanged,
                    'fields' => array_values(array_diff(array_keys($updates), ['password_hash'])),
                ],
            ]);

            return $account->fresh();
        });

        if ($passwordChanged || $flagsTightened) {
            $this->mailDriver->evictSession($updated->email);
        }

        return $updated;
    }

    private function assertQuotaCeiling(MailAccount $account, MailQuotaMb $quotaMb): void
    {
        $owner = $account->mailDomain?->user()->with('package')->first();
        $package = $owner?->package;

        if (! $package || $package->disk_quota_mb <= 0) {
            return;
        }

        if ($quotaMb->isUnlimited()) {
            throw ValidationException::withMessages([
                'quota_mb' => "Unlimited mailbox quota is not permitted under your package disk quota ({$package->disk_quota_mb} MB).",
            ]);
        }

        $userDomainIds = MailDomain::where('user_id', $owner->id)->pluck('id');
        $otherAllocatedMb = (int) MailAccount::whereIn('mail_domain_id', $userDomainIds)
            ->where('id', '!=', $account->id)
            ->sum('quota_mb');

        if (($otherAllocatedMb + $quotaMb->value) > $package->disk_quota_mb) {
            throw ValidationException::withMessages([
                'quota_mb' => "Requested quota ({$quotaMb->value} MB) exceeds package disk quota ceiling ({$package->disk_quota_mb} MB).",
            ]);
        }
    }
}
