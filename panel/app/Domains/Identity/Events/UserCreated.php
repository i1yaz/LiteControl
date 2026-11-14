<?php

declare(strict_types=1);

namespace App\Domains\Identity\Events;

use App\Domains\Identity\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserCreated
{
    use Dispatchable, SerializesModels;

    public bool $domainCreated = false;

    public ?string $warning = null;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly User $user,
        public readonly array $data = [],
        public readonly ?User $actor = null,
    ) {}
}

