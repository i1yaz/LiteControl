<?php

declare(strict_types=1);

namespace App\Domains\Mail\Infrastructure;

use App\Domains\Mail\Contracts\DnsTxtResolverInterface;

class PhpDnsTxtResolver implements DnsTxtResolverInterface
{
    /**
     * @return list<string>
     */
    public function resolveTxtRecords(string $hostname): array
    {
        $records = @dns_get_record($hostname, DNS_TXT);
        if (! is_array($records)) {
            return [];
        }

        $values = [];
        foreach ($records as $record) {
            if (isset($record['txt']) && is_string($record['txt'])) {
                $values[] = $record['txt'];
            }
        }

        return $values;
    }
}
