<?php

declare(strict_types=1);

namespace App\Domains\Shared\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class MailDnsRecordsChanged
{
    use Dispatchable;

    /**
     * @param  array<int, array<string, mixed>>  $records
     */
    public function __construct(
        public readonly string $domain,
        public readonly array $records,
    ) {}
}
