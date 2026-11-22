<?php

declare(strict_types=1);

namespace App\Domains\Dns\Queries;

use App\Domains\Dns\Contracts\PowerDnsClientInterface;
use App\Domains\Dns\ValueObjects\ZoneNameNormalizer;

final class SearchPdnsDataQuery
{
    public function __construct(
        private readonly PowerDnsClientInterface $powerDnsClient,
        private readonly ZoneNameNormalizer $normalizer,
    ) {}

    /**
     * Execute cross-zone record, comment, and zone search via PowerDNS `/search-data`.
     *
     * @return list<array<string, mixed>>
     */
    public function handle(string $term, int $max = 100): array
    {
        $trimmed = trim($term);
        if ($trimmed === '') {
            return [];
        }

        $rawResults = $this->powerDnsClient->searchData($trimmed, $max);

        $results = [];
        foreach ($rawResults as $item) {
            if (! is_array($item)) {
                continue;
            }

            $zoneId = (string) ($item['zone_id'] ?? $item['zone'] ?? '');
            $name = (string) ($item['name'] ?? '');

            $results[] = [
                'object_type' => (string) ($item['object_type'] ?? 'record'),
                'zone_id' => $zoneId,
                'zone' => (string) ($item['zone'] ?? $zoneId),
                'zone_display' => $this->normalizer->toDisplayUtf8($zoneId),
                'name' => $name,
                'name_display' => $this->normalizer->toDisplayUtf8($name),
                'type' => (string) ($item['type'] ?? ''),
                'content' => (string) ($item['content'] ?? ''),
                'ttl' => isset($item['ttl']) ? (int) $item['ttl'] : null,
                'disabled' => (bool) ($item['disabled'] ?? false),
            ];
        }

        return $results;
    }
}
