<?php

declare(strict_types=1);

namespace App\Domains\Dns\ValueObjects;

use InvalidArgumentException;

final readonly class UpdateZoneModePayload
{
    public const ALLOWED_KINDS = ['Native', 'Primary', 'Secondary'];

    public string $zoneId;

    public string $kind;

    /** @var list<string> */
    public array $masters;

    /** @var list<string> */
    public array $allowAxfrFrom;

    /** @var list<string> */
    public array $alsoNotify;

    public bool $confirmOverwrite;

    /**
     * @param  list<string>|string|null  $masters
     * @param  list<string>|string|null  $allowAxfrFrom
     * @param  list<string>|string|null  $alsoNotify
     */
    public function __construct(
        string $zoneId,
        string $kind,
        array|string|null $masters = [],
        array|string|null $allowAxfrFrom = [],
        array|string|null $alsoNotify = [],
        bool $confirmOverwrite = false,
        ZoneNameNormalizer $normalizer = new ZoneNameNormalizer,
    ) {
        $canonicalZoneId = $normalizer->ensureTrailingDot($zoneId);
        if ($canonicalZoneId === '.') {
            throw new InvalidArgumentException('Zone ID cannot be empty.');
        }

        $normalizedKind = match (strtolower(trim($kind))) {
            'native' => 'Native',
            'primary', 'master' => 'Primary',
            'secondary', 'slave' => 'Secondary',
            default => throw new InvalidArgumentException("Unsupported PowerDNS zone kind: {$kind}"),
        };

        $parsedMasters = self::parseEntryList($masters);
        $parsedAllowAxfr = self::parseEntryList($allowAxfrFrom);
        $parsedAlsoNotify = self::parseEntryList($alsoNotify);

        if ($normalizedKind === 'Secondary') {
            if ($parsedMasters === []) {
                throw new InvalidArgumentException(
                    'Secondary zones require at least one valid upstream primary (master) IP address.'
                );
            }

            foreach ($parsedMasters as $master) {
                if (! PdnsDaemonConfig::isValidIpWithOptionalPort($master)) {
                    throw new InvalidArgumentException(
                        "Invalid upstream primary IP or IP:port entry: {$master}"
                    );
                }
            }
        } else {
            $parsedMasters = [];
        }

        foreach ($parsedAllowAxfr as $entry) {
            if (! PdnsDaemonConfig::isValidIpOrCidr($entry)) {
                throw new InvalidArgumentException(
                    "Invalid IP or CIDR '{$entry}' in ALLOW-AXFR-FROM."
                );
            }
        }

        foreach ($parsedAlsoNotify as $entry) {
            if (! PdnsDaemonConfig::isValidIpWithOptionalPort($entry)) {
                throw new InvalidArgumentException(
                    "Invalid IP or IP:port '{$entry}' in ALSO-NOTIFY."
                );
            }
        }

        $this->zoneId = $canonicalZoneId;
        $this->kind = $normalizedKind;
        $this->masters = array_values(array_unique($parsedMasters));
        $this->allowAxfrFrom = array_values(array_unique($parsedAllowAxfr));
        $this->alsoNotify = array_values(array_unique($parsedAlsoNotify));
        $this->confirmOverwrite = $confirmOverwrite;
    }

    /**
     * Build the PowerDNS `PUT /api/v1/servers/localhost/zones/{zone_id}` payload.
     *
     * @return array<string, mixed>
     */
    public function toPowerDnsZonePayload(): array
    {
        if ($this->kind === 'Secondary') {
            return [
                'kind' => 'Secondary',
                'masters' => $this->masters,
            ];
        }

        return [
            'kind' => $this->kind,
            'masters' => [],
            'soa_edit_api' => 'DEFAULT',
            'api_rectify' => true,
        ];
    }

    /**
     * @param  list<string>|string|null  $raw
     * @return list<string>
     */
    public static function parseEntryList(array|string|null $raw): array
    {
        if ($raw === null) {
            return [];
        }

        if (is_string($raw)) {
            $items = preg_split('/[\s,;]+/', $raw) ?: [];
        } else {
            $items = $raw;
        }

        $cleaned = [];
        foreach ($items as $item) {
            if (! is_string($item)) {
                continue;
            }
            $trimmed = trim($item);
            if ($trimmed !== '') {
                $cleaned[] = $trimmed;
            }
        }

        return $cleaned;
    }
}
