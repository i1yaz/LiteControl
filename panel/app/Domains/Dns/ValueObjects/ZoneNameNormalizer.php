<?php

declare(strict_types=1);

namespace App\Domains\Dns\ValueObjects;

use InvalidArgumentException;

final readonly class ZoneNameNormalizer
{
    private const MAX_TOTAL_LENGTH = 253;

    private const MAX_LABEL_LENGTH = 63;

    /**
     * Normalize a zone name based on its type ('forward', 'reverse_ipv4', 'reverse_ipv6').
     *
     * @throws InvalidArgumentException
     */
    public function normalize(string $input, string $zoneType = 'forward'): string
    {
        return match ($zoneType) {
            'forward' => $this->normalizeForwardZone($input),
            'reverse_ipv4' => $this->fromIpv4Cidr($input),
            'reverse_ipv6' => $this->fromIpv6Cidr($input),
            default => throw new InvalidArgumentException("Unsupported zone type: {$zoneType}"),
        };
    }

    /**
     * Normalize a forward DNS zone or FQDN to canonical ASCII/Punycode with a trailing dot.
     *
     * @throws InvalidArgumentException
     */
    public function normalizeForwardZone(string $input): string
    {
        $trimmed = trim($input);
        if ($trimmed === '' || $trimmed === '.') {
            throw new InvalidArgumentException('Zone name cannot be empty.');
        }

        if (preg_match('/\s/', $trimmed) === 1) {
            throw new InvalidArgumentException('Zone name cannot contain whitespace.');
        }

        $withoutTrailingDot = rtrim($trimmed, '.');
        if ($withoutTrailingDot === '' || str_starts_with($withoutTrailingDot, '.') || str_contains($withoutTrailingDot, '..')) {
            throw new InvalidArgumentException('Zone name contains empty DNS labels.');
        }

        $rawLabels = explode('.', $withoutTrailingDot);
        if (count($rawLabels) < 2) {
            throw new InvalidArgumentException('Zone name must include at least two labels (e.g. example.com).');
        }

        $asciiLabels = [];
        foreach ($rawLabels as $label) {
            $asciiLabel = $this->labelToAscii($label);

            if (strlen($asciiLabel) > self::MAX_LABEL_LENGTH) {
                throw new InvalidArgumentException(
                    sprintf('DNS label "%s" exceeds the maximum length of %d characters.', $asciiLabel, self::MAX_LABEL_LENGTH)
                );
            }

            if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $asciiLabel) !== 1) {
                throw new InvalidArgumentException(
                    sprintf('DNS label "%s" contains invalid characters or starts/ends with a hyphen.', $label)
                );
            }

            $asciiLabels[] = $asciiLabel;
        }

        $asciiDomain = implode('.', $asciiLabels);
        if (strlen($asciiDomain) > self::MAX_TOTAL_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('Zone name exceeds the maximum total length of %d characters.', self::MAX_TOTAL_LENGTH)
            );
        }

        return $asciiDomain.'.';
    }

    /**
     * Normalize an arbitrary hostname/nameserver FQDN to canonical ASCII/Punycode with a trailing dot.
     *
     * @throws InvalidArgumentException
     */
    public function normalizeFqdn(string $input): string
    {
        return $this->normalizeForwardZone($input);
    }

    /**
     * Convert an IPv4 CIDR (e.g. 192.0.2.0/24), octet prefix (e.g. 192.0.2), or existing in-addr.arpa zone
     * into a canonical trailing-dot reverse zone name (e.g. 2.0.192.in-addr.arpa.).
     *
     * @throws InvalidArgumentException
     */
    public function fromIpv4Cidr(string $cidrOrZone): string
    {
        $trimmed = strtolower(trim($cidrOrZone));
        if ($trimmed === '') {
            throw new InvalidArgumentException('IPv4 reverse zone CIDR or prefix cannot be empty.');
        }

        $withoutDot = rtrim($trimmed, '.');
        if (str_ends_with($withoutDot, '.in-addr.arpa')) {
            return $this->validateExistingIpv4Arpa($withoutDot);
        }

        if (str_contains($withoutDot, '/')) {
            [$ipPart, $prefixPart] = explode('/', $withoutDot, 2);
            $ipPart = trim($ipPart);
            $prefixPart = trim($prefixPart);

            if (filter_var($ipPart, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new InvalidArgumentException("Invalid IPv4 address in CIDR: {$cidrOrZone}");
            }

            if (! ctype_digit($prefixPart)) {
                throw new InvalidArgumentException("Invalid IPv4 prefix length in CIDR: {$cidrOrZone}");
            }

            $prefixLength = (int) $prefixPart;
            if (! in_array($prefixLength, [8, 16, 24, 32], true)) {
                throw new InvalidArgumentException(
                    'IPv4 reverse zone prefix length must be octet-aligned (/8, /16, /24, or /32).'
                );
            }

            $octets = explode('.', $ipPart);
            $octetCount = intdiv($prefixLength, 8);
            $significantOctets = array_slice($octets, 0, $octetCount);

            return implode('.', array_reverse($significantOctets)).'.in-addr.arpa.';
        }

        // Support shorthand 1-3 octet prefixes like "192.0.2" or full IPv4 "192.0.2.0" (treated as /24 if trailing .0, or /32)
        $parts = explode('.', $withoutDot);
        if (count($parts) >= 1 && count($parts) <= 3) {
            foreach ($parts as $octet) {
                if (! ctype_digit($octet) || (int) $octet < 0 || (int) $octet > 255) {
                    throw new InvalidArgumentException("Invalid IPv4 prefix: {$cidrOrZone}");
                }
            }

            return implode('.', array_reverse(array_map(static fn (string $o): string => (string) (int) $o, $parts))).'.in-addr.arpa.';
        }

        if (filter_var($withoutDot, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $octets = explode('.', $withoutDot);
            // If last octet is 0, default to /24 reverse zone
            $significant = $octets[3] === '0' ? array_slice($octets, 0, 3) : $octets;

            return implode('.', array_reverse($significant)).'.in-addr.arpa.';
        }

        throw new InvalidArgumentException(
            'Invalid IPv4 reverse zone input. Provide a CIDR such as 192.0.2.0/24 or a valid in-addr.arpa zone.'
        );
    }

    /**
     * Convert an IPv6 CIDR/prefix (e.g. 2001:db8:abcd::/48) or existing ip6.arpa zone
     * into a canonical trailing-dot reverse zone name (e.g. d.c.b.a.8.b.d.0.1.0.0.2.ip6.arpa.).
     *
     * @throws InvalidArgumentException
     */
    public function fromIpv6Cidr(string $cidrOrZone): string
    {
        $trimmed = strtolower(trim($cidrOrZone));
        if ($trimmed === '') {
            throw new InvalidArgumentException('IPv6 reverse zone CIDR or prefix cannot be empty.');
        }

        $withoutDot = rtrim($trimmed, '.');
        if (str_ends_with($withoutDot, '.ip6.arpa')) {
            return $this->validateExistingIpv6Arpa($withoutDot);
        }

        if (! str_contains($withoutDot, '/')) {
            throw new InvalidArgumentException(
                'IPv6 reverse zone input must include a nibble-aligned CIDR prefix length (e.g. 2001:db8:abcd::/48).'
            );
        }

        [$ipPart, $prefixPart] = explode('/', $withoutDot, 2);
        $ipPart = trim($ipPart);
        $prefixPart = trim($prefixPart);

        if (filter_var($ipPart, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            throw new InvalidArgumentException("Invalid IPv6 address in CIDR: {$cidrOrZone}");
        }

        if (! ctype_digit($prefixPart)) {
            throw new InvalidArgumentException("Invalid IPv6 prefix length in CIDR: {$cidrOrZone}");
        }

        $prefixLength = (int) $prefixPart;
        if ($prefixLength < 4 || $prefixLength > 128 || ($prefixLength % 4) !== 0) {
            throw new InvalidArgumentException(
                'IPv6 reverse zone prefix length must be between /4 and /128 and divisible by 4 (e.g. /32, /48, /56, /64).'
            );
        }

        $packed = @inet_pton($ipPart);
        if ($packed === false || strlen($packed) !== 16) {
            throw new InvalidArgumentException("Unable to parse IPv6 address: {$ipPart}");
        }

        $fullHex = bin2hex($packed);
        $nibbleCount = intdiv($prefixLength, 4);
        $prefixNibbles = str_split(substr($fullHex, 0, $nibbleCount));

        return implode('.', array_reverse($prefixNibbles)).'.ip6.arpa.';
    }

    /**
     * Check whether a canonical zone name is a reverse DNS zone (.in-addr.arpa. or .ip6.arpa.).
     */
    public function isReverseZone(string $zoneName): bool
    {
        return $this->reverseType($zoneName) !== null;
    }

    /**
     * Determine the reverse DNS family ('ipv4' | 'ipv6' | null).
     */
    public function reverseType(string $zoneName): ?string
    {
        $normalized = strtolower(rtrim(trim($zoneName), '.'));

        if (str_ends_with($normalized, '.in-addr.arpa')) {
            return 'ipv4';
        }

        if (str_ends_with($normalized, '.ip6.arpa')) {
            return 'ipv6';
        }

        return null;
    }

    /**
     * Convert a canonical ASCII/Punycode zone name to its human-readable UTF-8 display form (without trailing dot).
     */
    public function toDisplayUtf8(string $zoneName): string
    {
        $withoutDot = rtrim(trim($zoneName), '.');
        if ($withoutDot === '') {
            return '';
        }

        $labels = explode('.', $withoutDot);
        $utf8Labels = [];

        foreach ($labels as $label) {
            if (str_starts_with(strtolower($label), 'xn--') && function_exists('idn_to_utf8')) {
                $decoded = idn_to_utf8($label, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
                $utf8Labels[] = is_string($decoded) && $decoded !== '' ? $decoded : $label;
            } else {
                $utf8Labels[] = $label;
            }
        }

        return implode('.', $utf8Labels);
    }

    /**
     * Canonicalize an existing zone identifier so it always ends with a single trailing dot.
     */
    public function ensureTrailingDot(string $zoneId): string
    {
        $trimmed = strtolower(rtrim(trim($zoneId), '.'));
        if ($trimmed === '') {
            throw new InvalidArgumentException('Zone identifier cannot be empty.');
        }

        if (str_ends_with($trimmed, '.in-addr.arpa')) {
            return $this->validateExistingIpv4Arpa($trimmed);
        }

        if (str_ends_with($trimmed, '.ip6.arpa')) {
            return $this->validateExistingIpv6Arpa($trimmed);
        }

        return $this->normalizeForwardZone($trimmed);
    }

    private function labelToAscii(string $label): string
    {
        $trimmed = trim($label);
        if ($trimmed === '') {
            throw new InvalidArgumentException('DNS label cannot be empty.');
        }

        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($trimmed, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false || $ascii === '') {
                throw new InvalidArgumentException(sprintf('Invalid domain label "%s".', $label));
            }

            return strtolower($ascii);
        }

        return strtolower($trimmed);
    }

    private function validateExistingIpv4Arpa(string $withoutDot): string
    {
        $prefix = substr($withoutDot, 0, -strlen('.in-addr.arpa'));
        $octets = explode('.', $prefix);

        if ($prefix === '' || count($octets) < 1 || count($octets) > 4) {
            throw new InvalidArgumentException("Invalid IPv4 reverse zone name: {$withoutDot}");
        }

        foreach ($octets as $octet) {
            if (! ctype_digit($octet) || (int) $octet < 0 || (int) $octet > 255) {
                throw new InvalidArgumentException("Invalid octet '{$octet}' in IPv4 reverse zone: {$withoutDot}");
            }
        }

        $normalizedOctets = array_map(static fn (string $o): string => (string) (int) $o, $octets);

        return implode('.', $normalizedOctets).'.in-addr.arpa.';
    }

    private function validateExistingIpv6Arpa(string $withoutDot): string
    {
        $prefix = substr($withoutDot, 0, -strlen('.ip6.arpa'));
        $nibbles = explode('.', $prefix);

        if ($prefix === '' || count($nibbles) < 1 || count($nibbles) > 32) {
            throw new InvalidArgumentException("Invalid IPv6 reverse zone name: {$withoutDot}");
        }

        foreach ($nibbles as $nibble) {
            if (strlen($nibble) !== 1 || ! ctype_xdigit($nibble)) {
                throw new InvalidArgumentException("Invalid hexadecimal nibble '{$nibble}' in IPv6 reverse zone: {$withoutDot}");
            }
        }

        return implode('.', $nibbles).'.ip6.arpa.';
    }
}
