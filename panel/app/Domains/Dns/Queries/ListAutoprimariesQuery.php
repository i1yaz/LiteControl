<?php

declare(strict_types=1);

namespace App\Domains\Dns\Queries;

use App\Domains\Dns\Contracts\PowerDnsClientInterface;
use App\Domains\Dns\Exceptions\PowerDnsException;
use App\Domains\Dns\ValueObjects\ZoneNameNormalizer;

final class ListAutoprimariesQuery
{
    public function __construct(
        private readonly PowerDnsClientInterface $powerDnsClient,
        private readonly ZoneNameNormalizer $normalizer = new ZoneNameNormalizer,
    ) {}

    /**
     * Retrieve and normalize configured PowerDNS Autoprimaries (Supermasters).
     *
     * @return list<array{
     *     ip: string,
     *     nameserver: string,
     *     display_nameserver: string,
     *     is_idn: bool,
     *     account: string
     * }>
     *
     * @throws PowerDnsException
     */
    public function handle(): array
    {
        $rawList = $this->powerDnsClient->listAutoprimaries();
        $entries = [];

        foreach ($rawList as $item) {
            if (! is_array($item)) {
                continue;
            }

            $ip = trim((string) ($item['ip'] ?? ''));
            $nameserver = trim((string) ($item['nameserver'] ?? ''));
            $account = trim((string) ($item['account'] ?? ''));

            if ($ip === '' || $nameserver === '') {
                continue;
            }

            $displayNs = $this->normalizer->toDisplayUtf8($nameserver);
            $asciiBase = rtrim($nameserver, '.');

            $entries[] = [
                'ip' => $ip,
                'nameserver' => $nameserver,
                'display_nameserver' => $displayNs,
                'is_idn' => strtolower($displayNs) !== strtolower($asciiBase),
                'account' => $account,
            ];
        }

        usort($entries, static fn (array $a, array $b): int => [$a['ip'], $a['nameserver']] <=> [$b['ip'], $b['nameserver']]);

        return $entries;
    }
}
