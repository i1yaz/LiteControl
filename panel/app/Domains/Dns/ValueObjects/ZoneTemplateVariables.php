<?php

declare(strict_types=1);

namespace App\Domains\Dns\ValueObjects;

use InvalidArgumentException;

final readonly class ZoneTemplateVariables
{
    public string $domain;

    public string $ipv4;

    public string $ipv6;

    public string $ns1;

    public string $ns2;

    public function __construct(
        string $domain,
        string $ipv4 = '',
        string $ipv6 = '',
        string $ns1 = '',
        string $ns2 = '',
        ?ZoneNameNormalizer $normalizer = null,
    ) {
        $norm = $normalizer ?? new ZoneNameNormalizer;

        $this->domain = $norm->normalizeForwardZone($domain);

        $trimmedIpv4 = trim($ipv4);
        if ($trimmedIpv4 !== '' && filter_var($trimmedIpv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException("Invalid IPv4 placeholder value: {$trimmedIpv4}");
        }
        $this->ipv4 = $trimmedIpv4;

        $trimmedIpv6 = trim($ipv6);
        if ($trimmedIpv6 !== '' && filter_var($trimmedIpv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            throw new InvalidArgumentException("Invalid IPv6 placeholder value: {$trimmedIpv6}");
        }
        $this->ipv6 = $trimmedIpv6;

        $trimmedNs1 = trim($ns1);
        $this->ns1 = $trimmedNs1 !== '' ? $norm->normalizeFqdn($trimmedNs1) : 'ns1.'.$this->domain;

        $trimmedNs2 = trim($ns2);
        $this->ns2 = $trimmedNs2 !== '' ? $norm->normalizeFqdn($trimmedNs2) : 'ns2.'.$this->domain;
    }

    public function bareDomain(): string
    {
        return rtrim($this->domain, '.');
    }
}
