<?php

declare(strict_types=1);

namespace App\Domains\Dns\ValueObjects;

use InvalidArgumentException;

final readonly class DefaultNameserverEntry
{
    public string $hostname;

    public string $ipv4;

    public string $ipv6;

    public function __construct(
        string $hostname,
        string $ipv4 = '',
        string $ipv6 = '',
        ?ZoneNameNormalizer $normalizer = null,
    ) {
        $norm = $normalizer ?? new ZoneNameNormalizer;
        $this->hostname = $norm->normalizeFqdn($hostname);

        $trimmedIpv4 = trim($ipv4);
        if ($trimmedIpv4 !== '' && filter_var($trimmedIpv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException("Invalid IPv4 glue address for nameserver {$this->hostname}: {$trimmedIpv4}");
        }
        $this->ipv4 = $trimmedIpv4;

        $trimmedIpv6 = trim($ipv6);
        if ($trimmedIpv6 !== '' && filter_var($trimmedIpv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            throw new InvalidArgumentException("Invalid IPv6 glue address for nameserver {$this->hostname}: {$trimmedIpv6}");
        }
        $this->ipv6 = $trimmedIpv6;
    }

    public function baseDomain(): string
    {
        $withoutDot = rtrim($this->hostname, '.');
        $labels = explode('.', $withoutDot);

        if (count($labels) <= 2) {
            return $this->hostname;
        }

        return implode('.', array_slice($labels, 1)).'.';
    }

    /**
     * @return array{hostname: string, ipv4: string, ipv6: string}
     */
    public function toStorageArray(): array
    {
        return [
            'hostname' => $this->hostname,
            'ipv4' => $this->ipv4,
            'ipv6' => $this->ipv6,
        ];
    }
}
