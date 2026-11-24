<?php

declare(strict_types=1);

namespace App\Domains\WebServer\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use App\Domains\Shared\Events\DomainOwnershipTransferred;
use App\Domains\WebServer\Models\Domain;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TransferDomainOwnershipAction
{
    public function __construct(
        protected ?AuditLoggerInterface $auditLogger = null,
    ) {}

    public function handle(Domain $domain, User $newOwner, ?User $actor = null, ?string $ipAddress = null): Domain
    {
        $previousUserId = (int) $domain->user_id;

        if ($previousUserId === (int) $newOwner->id) {
            return $domain;
        }

        $actor = $actor ?? Auth::user();
        $resolvedIp = $ipAddress ?? request()?->ip();

        return DB::transaction(function () use ($domain, $previousUserId, $newOwner, $actor, $resolvedIp): Domain {
            $domain->user_id = $newOwner->id;
            $domain->save();

            DomainOwnershipTransferred::dispatch(
                $domain->id,
                $domain->domain,
                $previousUserId,
                $newOwner->id,
                $actor?->id,
                $resolvedIp,
            );

            ($this->auditLogger ?? app(AuditLoggerInterface::class))->log([
                'user_id' => $actor?->id ?? $newOwner->id,
                'action' => 'domain.transfer_ownership',
                'command' => "domain transfer {$domain->domain}",
                'status' => 'success',
                'ip_address' => $resolvedIp,
                'details' => [
                    'domain_id' => $domain->id,
                    'domain' => $domain->domain,
                    'previous_user_id' => $previousUserId,
                    'new_user_id' => $newOwner->id,
                ],
            ]);

            return $domain->refresh();
        });
    }
}
