<?php

declare(strict_types=1);

namespace App\Domains\Mail\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Mail\Models\MailAccount;
use App\Domains\Mail\Models\MailAlias;
use App\Domains\Mail\Models\MailDomain;
use App\Domains\Mail\ValueObjects\EmailAddress;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateMailAliasAction
{
    public const DEFAULT_MAX_FAN_OUT = 5;

    public function __construct(
        private readonly AuditLoggerInterface $auditLogger,
    ) {}

    public function execute(
        User $actor,
        MailDomain $mailDomain,
        string $sourceEmailInput,
        EmailAddress $destinationEmail,
        int $maxFanOut = self::DEFAULT_MAX_FAN_OUT,
    ): MailAlias {
        $normalizedSource = strtolower(trim($sourceEmailInput));
        $isCatchAll = str_starts_with($normalizedSource, '@');

        if ($isCatchAll) {
            $sourceDomain = substr($normalizedSource, 1);
            if ($sourceDomain !== $mailDomain->domain) {
                throw ValidationException::withMessages([
                    'source_email' => "Catch-all source '@{$sourceDomain}' does not match mail domain '{$mailDomain->domain}'.",
                ]);
            }
            $sourceEmail = '@'.$mailDomain->domain;
        } else {
            $validatedSource = new EmailAddress($normalizedSource);
            if ($validatedSource->domain !== $mailDomain->domain) {
                throw ValidationException::withMessages([
                    'source_email' => "Source email domain '{$validatedSource->domain}' does not match mail domain '{$mailDomain->domain}'.",
                ]);
            }
            $sourceEmail = $validatedSource->value;

            if (MailAccount::where('email', $sourceEmail)->exists()) {
                throw ValidationException::withMessages([
                    'source_email' => "Source address '{$sourceEmail}' collides with an existing mailbox.",
                ]);
            }
        }

        if (MailAlias::where('source_email', $sourceEmail)
            ->where('destination_email', $destinationEmail->value)
            ->exists()) {
            throw ValidationException::withMessages([
                'destination_email' => "Forwarder from '{$sourceEmail}' to '{$destinationEmail->value}' already exists.",
            ]);
        }

        $existingDestinations = MailAlias::where('source_email', $sourceEmail)->count();
        if ($existingDestinations >= $maxFanOut) {
            throw ValidationException::withMessages([
                'destination_email' => "Source '{$sourceEmail}' has reached the maximum fan-out cap of {$maxFanOut} destinations.",
            ]);
        }

        return DB::transaction(function () use ($actor, $mailDomain, $sourceEmail, $destinationEmail): MailAlias {
            $alias = MailAlias::create([
                'mail_domain_id' => $mailDomain->id,
                'source_email' => $sourceEmail,
                'destination_email' => $destinationEmail->value,
                'active' => true,
            ]);

            $this->auditLogger->log([
                'user_id' => $actor->id,
                'action' => 'mail.alias.created',
                'target_type' => MailAlias::class,
                'target_id' => $alias->id,
                'metadata' => [
                    'source_email' => $sourceEmail,
                    'destination_email' => $destinationEmail->value,
                    'mail_domain_id' => $mailDomain->id,
                ],
            ]);

            return $alias->fresh();
        });
    }
}
