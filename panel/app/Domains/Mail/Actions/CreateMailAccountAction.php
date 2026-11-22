<?php

declare(strict_types=1);

namespace App\Domains\Mail\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Mail\Models\MailAccount;
use App\Domains\Mail\Models\MailAlias;
use App\Domains\Mail\Models\MailDomain;
use App\Domains\Mail\Models\MailPendingPurge;
use App\Domains\Mail\ValueObjects\EmailAddress;
use App\Domains\Mail\ValueObjects\MailQuotaMb;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateMailAccountAction
{
    public function __construct(
        private readonly AuditLoggerInterface $auditLogger,
    ) {}

    public function execute(
        User $actor,
        MailDomain $mailDomain,
        EmailAddress $email,
        string $password,
        MailQuotaMb $quotaMb,
        bool $allowImap = true,
        bool $allowPop3 = true,
        bool $allowSmtp = true,
    ): MailAccount {
        if ($email->domain !== $mailDomain->domain) {
            throw ValidationException::withMessages([
                'email' => "Email domain '{$email->domain}' does not match mail domain '{$mailDomain->domain}'.",
            ]);
        }

        if ($this->hasTombstone($email)) {
            throw ValidationException::withMessages([
                'email' => "Address '{$email->value}' cannot be created while a pending purge tombstone exists.",
            ]);
        }

        if (MailAccount::where('email', $email->value)->exists()) {
            throw ValidationException::withMessages([
                'email' => "Mail account '{$email->value}' already exists.",
            ]);
        }

        if (MailAlias::where('source_email', $email->value)->exists()) {
            throw ValidationException::withMessages([
                'email' => "Address '{$email->value}' is already used by a mail forwarder/alias.",
            ]);
        }

        $this->assertLimits($mailDomain, $quotaMb);

        $passwordHash = self::hashPassword($password);

        return DB::transaction(function () use (
            $actor,
            $mailDomain,
            $email,
            $passwordHash,
            $quotaMb,
            $allowImap,
            $allowPop3,
            $allowSmtp
        ): MailAccount {
            $account = MailAccount::create([
                'mail_domain_id' => $mailDomain->id,
                'local_part' => $email->localPart,
                'email' => $email->value,
                'password_hash' => $passwordHash,
                'quota_mb' => $quotaMb->value,
                'used_bytes' => 0,
                'active' => true,
                'allow_imap' => $allowImap,
                'allow_pop3' => $allowPop3,
                'allow_smtp' => $allowSmtp,
            ]);

            $this->auditLogger->log([
                'user_id' => $actor->id,
                'action' => 'mail.account.created',
                'target_type' => MailAccount::class,
                'target_id' => $account->id,
                'metadata' => [
                    'email' => $account->email,
                    'mail_domain_id' => $mailDomain->id,
                    'quota_mb' => $quotaMb->value,
                ],
            ]);

            return $account;
        });
    }

    public static function hashPassword(string $password): string
    {
        $hash = password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 19456,
            'time_cost' => 2,
            'threads' => 1,
        ]);

        return '{ARGON2ID}'.$hash;
    }

    private function hasTombstone(EmailAddress $email): bool
    {
        return MailPendingPurge::where('email', $email->value)
            ->orWhere(function ($query) use ($email): void {
                $query->where('entity_type', 'domain')
                    ->where('domain', $email->domain);
            })
            ->exists();
    }

    private function assertLimits(MailDomain $mailDomain, MailQuotaMb $quotaMb): void
    {
        if ($mailDomain->max_accounts > 0 && $mailDomain->accounts()->count() >= $mailDomain->max_accounts) {
            throw ValidationException::withMessages([
                'email' => "Domain '{$mailDomain->domain}' has reached its maximum mail account limit ({$mailDomain->max_accounts}).",
            ]);
        }

        $owner = $mailDomain->user()->with('package')->first();
        $package = $owner?->package;

        if (! $package) {
            return;
        }

        $userDomainIds = MailDomain::where('user_id', $owner->id)->pluck('id');

        if ($package->max_email_accounts > 0) {
            $currentCount = MailAccount::whereIn('mail_domain_id', $userDomainIds)->count();
            if ($currentCount >= $package->max_email_accounts) {
                throw ValidationException::withMessages([
                    'email' => "Package limit of {$package->max_email_accounts} email accounts has been reached.",
                ]);
            }
        }

        if ($package->disk_quota_mb > 0) {
            if ($quotaMb->isUnlimited()) {
                throw ValidationException::withMessages([
                    'quota_mb' => "Unlimited mailbox quota is not permitted under your package disk quota ({$package->disk_quota_mb} MB).",
                ]);
            }

            $allocatedMb = (int) MailAccount::whereIn('mail_domain_id', $userDomainIds)->sum('quota_mb');
            if (($allocatedMb + $quotaMb->value) > $package->disk_quota_mb) {
                throw ValidationException::withMessages([
                    'quota_mb' => "Requested quota ({$quotaMb->value} MB) exceeds package disk quota ceiling ({$package->disk_quota_mb} MB).",
                ]);
            }
        }
    }
}
