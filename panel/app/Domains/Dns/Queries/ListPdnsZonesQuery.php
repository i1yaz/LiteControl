<?php

declare(strict_types=1);

namespace App\Domains\Dns\Queries;

use App\Domains\Dns\Contracts\PowerDnsClientInterface;
use App\Domains\Dns\ValueObjects\ZoneNameNormalizer;

final class ListPdnsZonesQuery
{
    public function __construct(
        private readonly PowerDnsClientInterface $powerDnsClient,
        private readonly ZoneNameNormalizer $normalizer,
    ) {}

    /**
     * Fetch all PowerDNS zones and normalize their attributes for UI rendering and tab filtering.
     *
     * @return list<array<string, mixed>>
     */
    public function handle(?string $zoneFilter = null): array
    {
        $rawZones = $this->powerDnsClient->listZones($zoneFilter);

        $zones = [];
        foreach ($rawZones as $zone) {
            if (! is_array($zone)) {
                continue;
            }

            $canonicalId = (string) ($zone['id'] ?? $zone['name'] ?? '');
            $canonicalName = (string) ($zone['name'] ?? $canonicalId);

            if ($canonicalName === '') {
                continue;
            }

            $asciiWithoutDot = rtrim($canonicalName, '.');
            $displayUtf8 = $this->normalizer->toDisplayUtf8($canonicalName);
            $reverseType = $this->normalizer->reverseType($canonicalName);
            $isReverse = $reverseType !== null;
            $kind = $this->normalizeKind((string) ($zone['kind'] ?? 'Native'));

            $masters = [];
            if (isset($zone['masters']) && is_array($zone['masters'])) {
                foreach ($zone['masters'] as $master) {
                    if (is_string($master) && trim($master) !== '') {
                        $masters[] = trim($master);
                    }
                }
            }

            $zones[] = [
                'id' => $canonicalId,
                'name' => $canonicalName,
                'ascii_name' => $asciiWithoutDot,
                'display_name' => $displayUtf8,
                'is_idn' => strtolower($displayUtf8) !== strtolower($asciiWithoutDot),
                'kind' => $kind,
                'is_reverse' => $isReverse,
                'reverse_type' => $reverseType,
                'zone_category' => $isReverse ? 'reverse' : 'forward',
                'serial' => (int) ($zone['serial'] ?? 0),
                'notified_serial' => (int) ($zone['notified_serial'] ?? 0),
                'edited_serial' => (int) ($zone['edited_serial'] ?? 0),
                'dnssec' => (bool) ($zone['dnssec'] ?? false),
                'soa_edit_api' => (string) ($zone['soa_edit_api'] ?? 'DEFAULT'),
                'api_rectify' => (bool) ($zone['api_rectify'] ?? true),
                'masters' => $masters,
                'account' => (string) ($zone['account'] ?? ''),
                'last_check' => isset($zone['last_check']) ? (int) $zone['last_check'] : null,
            ];
        }

        usort($zones, static fn (array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));

        return $zones;
    }

    private function normalizeKind(string $rawKind): string
    {
        return match (strtolower(trim($rawKind))) {
            'primary', 'master' => 'Primary',
            'secondary', 'slave' => 'Secondary',
            default => 'Native',
        };
    }
}
