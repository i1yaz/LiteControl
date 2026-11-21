<?php

declare(strict_types=1);

namespace App\Domains\Mail\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Mail\Jobs\ReconcileMailServerJob;
use App\Domains\Mail\Models\MailDomain;
use App\Domains\Mail\Models\MailPendingPurge;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use App\Domains\Shared\Contracts\DomainOwnershipCheckerInterface;
use App\Domains\Shared\Events\MailDnsRecordsChanged;
use App\Domains\Shared\ValueObjects\DomainName;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class EnableMailDomainAction
{
    private const RESERVED_DOMAINS = [
        'localhost',
        'localdomain',
        'example.com',
        'example.org',
        'example.net',
        'gmail.com',
        'googlemail.com',
        'outlook.com',
        'hotmail.com',
        'live.com',
        'msn.com',
        'yahoo.com',
        'icloud.com',
        'me.com',
        'mac.com',
        'aol.com',
        'proton.me',
        'protonmail.com',
        'zoho.com',
        'yandex.com',
        'mail.com',
        'gmx.com',
        'fastmail.com',
    ];

    private const RESERVED_TLDS = [
        'localhost',
        'localdomain',
        'local',
        'internal',
        'invalid',
        'test',
        'example',
    ];

    public function __construct(
        private readonly DomainOwnershipCheckerInterface $ownershipChecker,
        private readonly AuditLoggerInterface $auditLogger,
    ) {}

    public function execute(
        User $actor,
        User $owner,
        DomainName $domain,
        bool $adminOverride = false,
        int $defaultQuotaMb = 1024,
        int $maxAccounts = 50,
    ): MailDomain {
        if ($this->isDeniedDomain($domain->value)) {
            throw ValidationException::withMessages([
                'domain' => "Domain '{$domain->value}' is reserved or on the public-suffix denylist and cannot be enabled for mail.",
            ]);
        }

        $canBypass = $adminOverride && $actor->isAdmin();

        if (! $canBypass && ! $this->ownershipChecker->userOwnsDomain($owner->id, $domain)) {
            throw ValidationException::withMessages([
                'domain' => "Domain '{$domain->value}' is not owned by the target user.",
            ]);
        }

        if (MailPendingPurge::where('domain', $domain->value)->exists()) {
            throw ValidationException::withMessages([
                'domain' => "Domain '{$domain->value}' has a pending purge tombstone and cannot be re-enabled until cleanup completes.",
            ]);
        }

        if (MailDomain::where('domain', $domain->value)->exists()) {
            throw ValidationException::withMessages([
                'domain' => "Mail is already enabled for domain '{$domain->value}'.",
            ]);
        }

        $ownershipSource = $canBypass ? 'admin_override' : 'webserver';

        $mailDomain = DB::transaction(function () use ($actor, $owner, $domain, $ownershipSource, $defaultQuotaMb, $maxAccounts): MailDomain {
            $record = MailDomain::create([
                'user_id' => $owner->id,
                'domain' => $domain->value,
                'ownership_source' => $ownershipSource,
                'active' => true,
                'default_quota_mb' => $defaultQuotaMb,
                'max_accounts' => $maxAccounts,
            ]);

            $this->auditLogger->log([
                'user_id' => $actor->id,
                'action' => 'mail.domain.enabled',
                'target_type' => MailDomain::class,
                'target_id' => $record->id,
                'metadata' => [
                    'domain' => $domain->value,
                    'owner_id' => $owner->id,
                    'ownership_source' => $ownershipSource,
                ],
            ]);

            return $record;
        });

        ReconcileMailServerJob::dispatch('all');
        MailDnsRecordsChanged::dispatch($domain->value, []);

        return $mailDomain;
    }

    private function isDeniedDomain(string $domain): bool
    {
        foreach (self::RESERVED_DOMAINS as $denied) {
            if ($domain === $denied || str_ends_with($domain, '.'.$denied)) {
                return true;
            }
        }

        $parts = explode('.', $domain);
        $tld = end($parts);

        return in_array($tld, self::RESERVED_TLDS, true);
    }
}
