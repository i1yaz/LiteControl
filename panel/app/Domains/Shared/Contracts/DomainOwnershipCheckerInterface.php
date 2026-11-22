<?php

declare(strict_types=1);

namespace App\Domains\Shared\Contracts;

use App\Domains\Shared\ValueObjects\DomainName;

interface DomainOwnershipCheckerInterface
{
    public function userOwnsDomain(int $userId, DomainName $domain): bool;
}
