<?php

declare(strict_types=1);

namespace App\Domains\Mail\Contracts;

interface DnsTxtResolverInterface
{
    /**
     * Resolve DNS TXT record values for the given hostname.
     *
     * @return list<string>
     */
    public function resolveTxtRecords(string $hostname): array;
}
