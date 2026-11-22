<?php

declare(strict_types=1);

namespace App\Domains\WebServer\Infrastructure;

use App\Domains\Shared\Contracts\DomainOwnershipCheckerInterface;
use App\Domains\Shared\ValueObjects\DomainName;
use App\Domains\WebServer\Models\Domain;

class EloquentDomainOwnershipChecker implements DomainOwnershipCheckerInterface
{
    public function userOwnsDomain(int $userId, DomainName $domain): bool
    {
        return Domain::query()
            ->where('user_id', $userId)
            ->where('domain', $domain->value)
            ->exists();
    }
}
