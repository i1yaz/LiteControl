<?php

declare(strict_types=1);

namespace App\Domains\Shared\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class DomainOwnershipTransferred
{
    use Dispatchable;

    public function __construct(
        public int $domainId,
        public string $domainName,
        public int $previousUserId,
        public int $newUserId,
        public ?int $actorId = null,
        public ?string $ipAddress = null,
    ) {}
}
