<?php

declare(strict_types=1);

namespace App\Domains\Dns\ValueObjects;

use InvalidArgumentException;

final readonly class RecordNormalizer
{
    private const MAX_TOTAL_LENGTH = 253;

    private const MAX_LABEL_LENGTH = 63;

    private const TXT_MAX_CHUNK_BYTES = 255;

    public const SUPPORTED_TYPES = [
        'A',
        'AAAA',
        'CNAME',
        'MX',
        'TXT',
        'SRV',
        'NS',
        'PTR',
        'CAA',
        'SOA',
    ];

    public function __construct(
        private ZoneNameNormalizer $zoneNameNormalizer = new ZoneNameNormalizer,
    ) {}

    /**
     * Normalize a record owner name (relative label, `@`, wildcard, service prefix, or full FQDN)
     * into a canonical lowercase ASCII/Punycode FQDN with a trailing dot inside the given zone.
     *
     * @throws InvalidArgumentException
     */
    public function normalizeRecordName(string $name, string $zoneName): string
    {
        $canonicalZone = $this->zoneNameNormalizer->ensureTrailingDot($zoneName);
        $bareZone = rtrim($canonicalZone, '.');

        if ($name === '' || preg_match('/^\s|\s$/', $name) === 1 || preg_match('/\s/', $name) === 1) {
            throw new InvalidArgumentException('Record name cannot be empty or contain leading, trailing, or internal whitespace.');
        }

        if ($name === '@') {
            return $canonicalZone;
        }

        $hasTrailingDot = str_ends_with($name, '.');
        $withoutDot = rtrim($name, '.');

        if ($withoutDot === '' || str_starts_with($withoutDot, '.') || str_contains($withoutDot, '..')) {
            throw new InvalidArgumentException("Record name \"{$name}\" contains empty DNS labels.");
        }

        $asciiRelativeOrFull = $this->convertLabelsToAscii($withoutDot, allowUnderscore: true, allowWildcard: true);

        if ($asciiRelativeOrFull === $bareZone) {
            $fqdnWithoutDot = $bareZone;
        } elseif (str_ends_with($asciiRelativeOrFull, '.'.$bareZone)) {
            $fqdnWithoutDot = $asciiRelativeOrFull;
        } elseif ($hasTrailingDot) {
            throw new InvalidArgumentException(
                sprintf('Record name "%s." is outside the authoritative zone "%s".', $asciiRelativeOrFull, $canonicalZone)
            );
        } else {
            $fqdnWithoutDot = $asciiRelativeOrFull.'.'.$bareZone;
        }

        if (strlen($fqdnWithoutDot) > self::MAX_TOTAL_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('Canonical record name "%s." exceeds the maximum length of %d characters.', $fqdnWithoutDot, self::MAX_TOTAL_LENGTH)
            );
        }

        return $fqdnWithoutDot.'.';
    }

    /**
     * Convert a canonical record FQDN to a human-readable UTF-8 display name (decoding `xn--` Punycode labels).
     */
    public function toDisplayUtf8(string $fqdn): string
    {
        return $this->zoneNameNormalizer->toDisplayUtf8($fqdn);
    }

    /**
     * Normalize a domain target (`CNAME`, `MX`, `NS`, `PTR`, `SRV`, `SOA` primary NS) to a canonical
     * trailing-dot ASCII/Punycode FQDN. Relative targets (or `@`) are resolved against `$zoneName` when provided.
     *
     * @throws InvalidArgumentException
     */
    public function normalizeTargetFqdn(
        string $target,
        ?string $zoneName = null,
        bool $allowRootDot = false,
        bool $allowUnderscore = false,
    ): string {
        if ($target === '' || preg_match('/^\s|\s$/', $target) === 1 || preg_match('/\s/', $target) === 1) {
            throw new InvalidArgumentException('Target hostname cannot be empty or contain whitespace.');
        }

        if ($allowRootDot && $target === '.') {
            return '.';
        }

        $canonicalZone = $zoneName !== null && trim($zoneName) !== ''
            ? $this->zoneNameNormalizer->ensureTrailingDot($zoneName)
            : null;
        $bareZone = $canonicalZone !== null ? rtrim($canonicalZone, '.') : null;

        if ($target === '@') {
            if ($canonicalZone === null) {
                throw new InvalidArgumentException('Cannot resolve "@" target without a zone context.');
            }

            return $canonicalZone;
        }

        $hasTrailingDot = str_ends_with($target, '.');
        $withoutDot = rtrim($target, '.');

        if ($withoutDot === '' || str_starts_with($withoutDot, '.') || str_contains($withoutDot, '..')) {
            throw new InvalidArgumentException("Target hostname \"{$target}\" contains empty DNS labels.");
        }

        $asciiTarget = $this->convertLabelsToAscii($withoutDot, allowUnderscore: $allowUnderscore, allowWildcard: false);

        if (! str_contains($asciiTarget, '.')) {
            if (! $hasTrailingDot && $bareZone !== null) {
                $asciiTarget = $asciiTarget.'.'.$bareZone;
            } else {
                throw new InvalidArgumentException(
                    sprintf('Target hostname "%s" must be a fully-qualified domain name with at least two labels.', $target)
                );
            }
        }

        if (strlen($asciiTarget) > self::MAX_TOTAL_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('Target FQDN "%s." exceeds the maximum total length of %d characters.', $asciiTarget, self::MAX_TOTAL_LENGTH)
            );
        }

        return $asciiTarget.'.';
    }

    /**
     * Normalize record content for any supported DNS record type (`A`, `AAAA`, `CNAME`, `MX`, `TXT`, `SRV`, `NS`, `PTR`, `CAA`, `SOA`).
     *
     * @param  string|array<string, mixed>  $content
     *
     * @throws InvalidArgumentException
     */
    public function normalizeContent(string $type, string|array $content, ?string $zoneName = null): string
    {
        $upperType = strtoupper(trim($type));

        return match ($upperType) {
            'A' => $this->normalizeARecord($this->requireStringContent($upperType, $content)),
            'AAAA' => $this->normalizeAaaaRecord($this->requireStringContent($upperType, $content)),
            'CNAME' => $this->normalizeCnameRecord($this->requireStringContent($upperType, $content), $zoneName),
            'NS' => $this->normalizeNsRecord($this->requireStringContent($upperType, $content), $zoneName),
            'PTR' => $this->normalizePtrRecord($this->requireStringContent($upperType, $content), $zoneName),
            'MX' => is_array($content)
                ? $this->normalizeMxRecord(
                    priority: $this->extractIntField($content, 'priority', 'MX'),
                    target: $this->extractStringField($content, ['target', 'mail_server', 'content'], 'MX'),
                    zoneName: $zoneName,
                )
                : $this->normalizeMxFromContentString(
                    content: $content,
                    fallbackPriority: null,
                    zoneName: $zoneName,
                ),
            'SRV' => is_array($content)
                ? $this->normalizeSrvRecord(
                    priority: $this->extractIntField($content, 'priority', 'SRV'),
                    weight: $this->extractIntField($content, 'weight', 'SRV'),
                    port: $this->extractIntField($content, 'port', 'SRV'),
                    target: $this->extractStringField($content, ['target', 'content'], 'SRV'),
                    zoneName: $zoneName,
                )
                : $this->normalizeSrvFromContentString($content, $zoneName),
            'CAA' => is_array($content)
                ? $this->normalizeCaaRecord(
                    flags: $this->extractIntField($content, 'flags', 'CAA'),
                    tag: $this->extractStringField($content, ['tag'], 'CAA'),
                    value: $this->extractStringField($content, ['value', 'content'], 'CAA'),
                )
                : $this->normalizeCaaFromContentString($content),
            'TXT' => $this->normalizeTxtRecord($this->requireStringContent($upperType, $content)),
            'SOA' => is_array($content)
                ? $this->normalizeSoaRecord(
                    primaryNs: $this->extractStringField($content, ['primary_ns', 'primaryNs', 'mname'], 'SOA'),
                    hostmaster: $this->extractStringField($content, ['hostmaster', 'rname', 'email'], 'SOA'),
                    serial: $this->extractIntField($content, 'serial', 'SOA'),
                    refresh: $this->extractIntField($content, 'refresh', 'SOA'),
                    retry: $this->extractIntField($content, 'retry', 'SOA'),
                    expire: $this->extractIntField($content, 'expire', 'SOA'),
                    minimum: $this->extractIntField($content, 'minimum', 'SOA'),
                    zoneName: $zoneName,
                )
                : $this->normalizeSoaFromContentString($content, $zoneName),
            default => throw new InvalidArgumentException("Unsupported DNS record type: {$type}"),
        };
    }

    /**
     * Validate and return an IPv4 address for an `A` record (rejecting leading/trailing whitespace).
     *
     * @throws InvalidArgumentException
     */
    public function normalizeARecord(string $ip): string
    {
        $this->assertNoSurroundingWhitespace($ip, 'A record IPv4 address');

        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException("Invalid IPv4 address for A record: \"{$ip}\".");
        }

        return $ip;
    }

    /**
     * Validate and return a canonical IPv6 address for an `AAAA` record (rejecting leading/trailing whitespace).
     *
     * @throws InvalidArgumentException
     */
    public function normalizeAaaaRecord(string $ip): string
    {
        $this->assertNoSurroundingWhitespace($ip, 'AAAA record IPv6 address');

        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            throw new InvalidArgumentException("Invalid IPv6 address for AAAA record: \"{$ip}\".");
        }

        $packed = @inet_pton($ip);
        if ($packed === false) {
            throw new InvalidArgumentException("Invalid IPv6 address for AAAA record: \"{$ip}\".");
        }

        $canonical = inet_ntop($packed);

        return is_string($canonical) ? strtolower($canonical) : strtolower($ip);
    }

    /**
     * Normalize a `CNAME` canonical target to a trailing-dot FQDN.
     *
     * @throws InvalidArgumentException
     */
    public function normalizeCnameRecord(string $target, ?string $zoneName = null): string
    {
        $this->assertNoSurroundingWhitespace($target, 'CNAME target');

        return $this->normalizeTargetFqdn($target, $zoneName, allowRootDot: false, allowUnderscore: true);
    }

    /**
     * Normalize an `NS` nameserver hostname to a trailing-dot FQDN.
     *
     * @throws InvalidArgumentException
     */
    public function normalizeNsRecord(string $nameserver, ?string $zoneName = null): string
    {
        $this->assertNoSurroundingWhitespace($nameserver, 'NS nameserver');

        return $this->normalizeTargetFqdn($nameserver, $zoneName, allowRootDot: false, allowUnderscore: false);
    }

    /**
     * Normalize a `PTR` reverse pointer target to a trailing-dot FQDN.
     *
     * @throws InvalidArgumentException
     */
    public function normalizePtrRecord(string $hostname, ?string $zoneName = null): string
    {
        $this->assertNoSurroundingWhitespace($hostname, 'PTR target hostname');

        return $this->normalizeTargetFqdn($hostname, $zoneName, allowRootDot: false, allowUnderscore: false);
    }

    /**
     * Serialize an `MX` record from separate Priority (`0-65535`) and Mail Server target into `"{priority} {target}."`.
     *
     * @throws InvalidArgumentException
     */
    public function normalizeMxRecord(int $priority, string $target, ?string $zoneName = null): string
    {
        if ($priority < 0 || $priority > 65535) {
            throw new InvalidArgumentException("MX priority must be between 0 and 65535, got {$priority}.");
        }

        $this->assertNoSurroundingWhitespace($target, 'MX mail server target');
        $canonicalTarget = $this->normalizeTargetFqdn($target, $zoneName, allowRootDot: true, allowUnderscore: false);

        return sprintf('%d %s', $priority, $canonicalTarget);
    }

    /**
     * Parse and normalize an `MX` record from a serialized string (`"10 mail.example.com"`) or bare target with fallback priority.
     *
     * @throws InvalidArgumentException
     */
    public function normalizeMxFromContentString(
        string $content,
        ?int $fallbackPriority = null,
        ?string $zoneName = null,
    ): string {
        $this->assertNoSurroundingWhitespace($content, 'MX record content');

        if ($content === '') {
            throw new InvalidArgumentException('MX record content cannot be empty.');
        }

        if (preg_match('/^(\d+)\s+(\S+)$/', $content, $matches) === 1) {
            $priority = (int) $matches[1];
            $target = $matches[2];

            return $this->normalizeMxRecord($priority, $target, $zoneName);
        }

        if ($fallbackPriority !== null) {
            return $this->normalizeMxRecord($fallbackPriority, $content, $zoneName);
        }

        throw new InvalidArgumentException(
            'MX record content must specify a priority (0-65535) and mail server hostname (e.g. "10 mail.example.com.").'
        );
    }

    /**
     * Serialize an `SRV` record from separate Priority, Weight, Port, and Target into `"{priority} {weight} {port} {target}."`.
     *
     * @throws InvalidArgumentException
     */
    public function normalizeSrvRecord(
        int $priority,
        int $weight,
        int $port,
        string $target,
        ?string $zoneName = null,
    ): string {
        if ($priority < 0 || $priority > 65535) {
            throw new InvalidArgumentException("SRV priority must be between 0 and 65535, got {$priority}.");
        }

        if ($weight < 0 || $weight > 65535) {
            throw new InvalidArgumentException("SRV weight must be between 0 and 65535, got {$weight}.");
        }

        if ($port < 0 || $port > 65535) {
            throw new InvalidArgumentException("SRV port must be between 0 and 65535, got {$port}.");
        }

        $this->assertNoSurroundingWhitespace($target, 'SRV target');
        $canonicalTarget = $this->normalizeTargetFqdn($target, $zoneName, allowRootDot: true, allowUnderscore: false);

        return sprintf('%d %d %d %s', $priority, $weight, $port, $canonicalTarget);
    }

    /**
     * Parse and normalize an `SRV` record from a combined string (`"10 20 5060 sip.example.com"`).
     *
     * @throws InvalidArgumentException
     */
    public function normalizeSrvFromContentString(string $content, ?string $zoneName = null): string
    {
        $this->assertNoSurroundingWhitespace($content, 'SRV record content');

        if (preg_match('/^(\d+)\s+(\d+)\s+(\d+)\s+(\S+)$/', $content, $matches) !== 1) {
            throw new InvalidArgumentException(
                'SRV record content must contain priority, weight, port, and target (e.g. "10 20 5060 sip.example.com.").'
            );
        }

        return $this->normalizeSrvRecord(
            priority: (int) $matches[1],
            weight: (int) $matches[2],
            port: (int) $matches[3],
            target: $matches[4],
            zoneName: $zoneName,
        );
    }

    /**
     * Serialize a `CAA` record from separate Flags (`0` or `128`), Tag (`issue`, `issuewild`, `iodef`), and Value
     * into `{flags} {tag} "{value}"`.
     *
     * @throws InvalidArgumentException
     */
    public function normalizeCaaRecord(int $flags, string $tag, string $value): string
    {
        if (! in_array($flags, [0, 128], true)) {
            throw new InvalidArgumentException("CAA flags must be 0 or 128, got {$flags}.");
        }

        $this->assertNoSurroundingWhitespace($tag, 'CAA tag');
        $normalizedTag = strtolower($tag);

        if (! in_array($normalizedTag, ['issue', 'issuewild', 'iodef'], true)) {
            throw new InvalidArgumentException(
                sprintf('Unsupported CAA tag "%s". Allowed tags are: issue, issuewild, iodef.', $tag)
            );
        }

        $this->assertNoSurroundingWhitespace($value, 'CAA value');
        if ($value === '') {
            throw new InvalidArgumentException('CAA record value cannot be empty.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('CAA record value cannot contain control characters.');
        }

        // Unwrap surrounding quotes if user provided them so we don't double-quote
        $rawValue = $value;
        if (strlen($rawValue) >= 2 && str_starts_with($rawValue, '"') && str_ends_with($rawValue, '"')) {
            $rawValue = substr($rawValue, 1, -1);
            $rawValue = str_replace(['\\"', '\\\\'], ['"', '\\'], $rawValue);
        }

        $escapedValue = str_replace(['\\', '"'], ['\\\\', '\\"'], $rawValue);

        return sprintf('%d %s "%s"', $flags, $normalizedTag, $escapedValue);
    }

    /**
     * Parse and normalize a `CAA` record from a combined string (`0 issue "letsencrypt.org"`).
     *
     * @throws InvalidArgumentException
     */
    public function normalizeCaaFromContentString(string $content): string
    {
        $this->assertNoSurroundingWhitespace($content, 'CAA record content');

        if (preg_match('/^(\d+)\s+([A-Za-z0-9]+)\s+(.+)$/s', $content, $matches) !== 1) {
            throw new InvalidArgumentException(
                'CAA record content must follow the format: <flags> <tag> "<value>" (e.g. \'0 issue "letsencrypt.org"\').'
            );
        }

        $flags = (int) $matches[1];
        $tag = $matches[2];
        $rawValuePart = $matches[3];

        if (strlen($rawValuePart) >= 2 && str_starts_with($rawValuePart, '"') && str_ends_with($rawValuePart, '"')) {
            $unquoted = substr($rawValuePart, 1, -1);
            $unquoted = str_replace(['\\"', '\\\\'], ['"', '\\'], $unquoted);

            return $this->normalizeCaaRecord($flags, $tag, $unquoted);
        }

        return $this->normalizeCaaRecord($flags, $tag, $rawValuePart);
    }

    /**
     * Normalize a `TXT` record string:
     * - Reject empty or control-character strings
     * - Escape internal backslashes and double quotes
     * - Auto-wrap in double quotes
     * - Auto-split strings longer than 255 bytes into space-separated quoted chunks (`"chunk1" "chunk2"`)
     *   without splitting multi-byte UTF-8 characters or escape sequences across chunk boundaries.
     *
     * @throws InvalidArgumentException
     */
    public function normalizeTxtRecord(string $content): string
    {
        if ($content === '') {
            throw new InvalidArgumentException('TXT record content cannot be empty.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $content) === 1) {
            throw new InvalidArgumentException('TXT record content cannot contain control characters or newlines.');
        }

        $rawText = $this->extractRawTxtValue($content);

        if ($rawText === '') {
            return '""';
        }

        $chunks = $this->splitTxtIntoEscapedChunks($rawText, self::TXT_MAX_CHUNK_BYTES);

        return implode(' ', array_map(static fn (string $chunk): string => '"'.$chunk.'"', $chunks));
    }

    /**
     * Normalize an `SOA` record from structured fields into PowerDNS canonical format:
     * `"{primaryNs}. {hostmaster}. {serial} {refresh} {retry} {expire} {minimum}"`.
     * Automatically converts `@` in Hostmaster Email (e.g. `hostmaster@example.com`) to DNS RNAME dot format
     * (escaping any literal dots in the local-part before `@` as `\.`).
     *
     * @throws InvalidArgumentException
     */
    public function normalizeSoaRecord(
        string $primaryNs,
        string $hostmaster,
        int $serial,
        int $refresh,
        int $retry,
        int $expire,
        int $minimum,
        ?string $zoneName = null,
    ): string {
        $this->assertNoSurroundingWhitespace($primaryNs, 'SOA primary nameserver');
        $this->assertNoSurroundingWhitespace($hostmaster, 'SOA hostmaster email');

        $canonicalPrimaryNs = $this->normalizeTargetFqdn($primaryNs, $zoneName, allowRootDot: false, allowUnderscore: false);
        $canonicalHostmaster = $this->normalizeSoaHostmaster($hostmaster, $zoneName);

        if ($serial < 0 || $serial > 4294967295) {
            throw new InvalidArgumentException("SOA serial must be an unsigned 32-bit integer (0-4294967295), got {$serial}.");
        }

        foreach ([
            'refresh' => $refresh,
            'retry' => $retry,
            'expire' => $expire,
            'minimum' => $minimum,
        ] as $field => $timerValue) {
            if ($timerValue < 1 || $timerValue > 2147483647) {
                throw new InvalidArgumentException("SOA {$field} must be a positive integer between 1 and 2147483647, got {$timerValue}.");
            }
        }

        return sprintf(
            '%s %s %d %d %d %d %d',
            $canonicalPrimaryNs,
            $canonicalHostmaster,
            $serial,
            $refresh,
            $retry,
            $expire,
            $minimum,
        );
    }

    /**
     * Parse and normalize an `SOA` record from a 7-part space-separated string.
     *
     * @throws InvalidArgumentException
     */
    public function normalizeSoaFromContentString(string $content, ?string $zoneName = null): string
    {
        $this->assertNoSurroundingWhitespace($content, 'SOA record content');

        $parts = preg_split('/\s+/', $content);
        if (! is_array($parts) || count($parts) !== 7) {
            throw new InvalidArgumentException(
                'SOA record content must contain 7 fields: <primary-ns> <hostmaster> <serial> <refresh> <retry> <expire> <minimum>.'
            );
        }

        foreach (array_slice($parts, 2) as $numericPart) {
            if (! ctype_digit($numericPart)) {
                throw new InvalidArgumentException("SOA numeric timers and serial must be non-negative integers, got \"{$numericPart}\".");
            }
        }

        return $this->normalizeSoaRecord(
            primaryNs: $parts[0],
            hostmaster: $parts[1],
            serial: (int) $parts[2],
            refresh: (int) $parts[3],
            retry: (int) $parts[4],
            expire: (int) $parts[5],
            minimum: (int) $parts[6],
            zoneName: $zoneName,
        );
    }

    /**
     * Convert a hostmaster email (`admin@example.com` or `first.last@example.com`) or existing RNAME (`hostmaster.example.com.`)
     * into a canonical trailing-dot SOA RNAME.
     *
     * @throws InvalidArgumentException
     */
    public function normalizeSoaHostmaster(string $hostmaster, ?string $zoneName = null): string
    {
        $this->assertNoSurroundingWhitespace($hostmaster, 'SOA hostmaster');

        if ($hostmaster === '') {
            throw new InvalidArgumentException('SOA hostmaster email cannot be empty.');
        }

        if (str_contains($hostmaster, '@')) {
            $atCount = substr_count($hostmaster, '@');
            if ($atCount !== 1) {
                throw new InvalidArgumentException("Invalid SOA hostmaster email address: \"{$hostmaster}\".");
            }

            [$localPart, $domainPart] = explode('@', $hostmaster, 2);
            if ($localPart === '' || $domainPart === '') {
                throw new InvalidArgumentException("Invalid SOA hostmaster email address: \"{$hostmaster}\".");
            }

            if (preg_match('/^[A-Za-z0-9._+-]+$/', $localPart) !== 1 || str_starts_with($localPart, '.') || str_ends_with($localPart, '.') || str_contains($localPart, '..')) {
                throw new InvalidArgumentException("Invalid local part in SOA hostmaster email: \"{$hostmaster}\".");
            }

            $canonicalDomain = $this->normalizeTargetFqdn($domainPart, $zoneName, allowRootDot: false, allowUnderscore: false);
            $escapedLocalPart = str_replace('.', '\\.', strtolower($localPart));

            return $escapedLocalPart.'.'.$canonicalDomain;
        }

        // Preserve escaped dot in RNAME local part if already present (e.g. first\.last.example.com.)
        if (str_contains($hostmaster, '\\.')) {
            $placeholder = '__ESCAPED_DOT_PLACEHOLDER__';
            $masked = str_replace('\\.', $placeholder, rtrim($hostmaster, '.'));
            $segments = explode('.', $masked);
            if (count($segments) < 3) {
                throw new InvalidArgumentException("Invalid SOA hostmaster RNAME: \"{$hostmaster}\".");
            }

            $localMasked = array_shift($segments);
            $localRestored = strtolower(str_replace($placeholder, '\\.', (string) $localMasked));
            if (preg_match('/^[a-z0-9_+-]+(?:\\\\\.[a-z0-9_+-]+)*$/', $localRestored) !== 1) {
                throw new InvalidArgumentException("Invalid SOA hostmaster RNAME local label: \"{$hostmaster}\".");
            }

            $domainCandidate = implode('.', array_map(
                static fn (string $seg): string => str_replace($placeholder, '.', $seg),
                $segments
            ));
            $canonicalDomain = $this->normalizeTargetFqdn($domainCandidate, $zoneName, allowRootDot: false, allowUnderscore: false);

            return $localRestored.'.'.$canonicalDomain;
        }

        return $this->normalizeTargetFqdn($hostmaster, $zoneName, allowRootDot: false, allowUnderscore: false);
    }

    /**
     * Convert a dot-separated sequence of labels to lowercase ASCII/Punycode while validating RFC label rules.
     *
     * @throws InvalidArgumentException
     */
    private function convertLabelsToAscii(string $domainWithoutTrailingDot, bool $allowUnderscore, bool $allowWildcard): string
    {
        $rawLabels = explode('.', $domainWithoutTrailingDot);
        $asciiLabels = [];

        foreach ($rawLabels as $index => $label) {
            if ($label === '') {
                throw new InvalidArgumentException('Domain name contains an empty label.');
            }

            if ($label === '*') {
                if (! $allowWildcard || $index !== 0) {
                    throw new InvalidArgumentException('Wildcard (*) is only permitted as the leftmost label of a record name.');
                }

                $asciiLabels[] = '*';

                continue;
            }

            $asciiLabel = $this->singleLabelToAscii($label);

            if (strlen($asciiLabel) > self::MAX_LABEL_LENGTH) {
                throw new InvalidArgumentException(
                    sprintf('DNS label "%s" exceeds the maximum length of %d characters.', $asciiLabel, self::MAX_LABEL_LENGTH)
                );
            }

            $pattern = $allowUnderscore
                ? '/^(?:_[a-z0-9](?:[a-z0-9_-]{0,60}[a-z0-9])?|[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)$/'
                : '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

            if (preg_match($pattern, $asciiLabel) !== 1) {
                throw new InvalidArgumentException(
                    sprintf('DNS label "%s" contains invalid characters or starts/ends with a hyphen.', $label)
                );
            }

            $asciiLabels[] = $asciiLabel;
        }

        return implode('.', $asciiLabels);
    }

    private function singleLabelToAscii(string $label): string
    {
        if (str_starts_with($label, '_') && strlen($label) > 1) {
            $rest = substr($label, 1);
            if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7E]/', $rest) === 1) {
                $converted = idn_to_ascii($rest, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
                if ($converted === false || $converted === '') {
                    throw new InvalidArgumentException(sprintf('Invalid IDN label "%s".', $label));
                }

                return '_'.strtolower($converted);
            }

            return strtolower($label);
        }

        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($label, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false || $ascii === '') {
                throw new InvalidArgumentException(sprintf('Invalid domain label "%s".', $label));
            }

            return strtolower($ascii);
        }

        return strtolower($label);
    }

    /**
     * Extract the raw unquoted text from either a plain string or an already-quoted (possibly multi-chunk) TXT value.
     */
    private function extractRawTxtValue(string $content): string
    {
        // Check if input is already formatted as one or more quoted TXT strings: `"chunk1" "chunk2"`
        if (str_starts_with($content, '"') && str_ends_with($content, '"')) {
            $pattern = '/\G"((?:[^"\\\\]|\\\\.)*)"(?:\s+|$)/s';
            if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER) > 0) {
                $matchedLength = 0;
                $parts = [];
                foreach ($matches as $match) {
                    $matchedLength += strlen($match[0]);
                    $parts[] = preg_replace_callback(
                        '/\\\\(.)/s',
                        static fn (array $m): string => $m[1],
                        $match[1]
                    ) ?? $match[1];
                }

                if ($matchedLength === strlen($content)) {
                    return implode('', $parts);
                }
            }
        }

        return $content;
    }

    /**
     * Split a raw TXT string into escaped chunks where each escaped chunk is at most `$maxChunkBytes` bytes (255).
     * Never splits inside a multi-byte UTF-8 codepoint or an escape sequence (`\"` or `\\`).
     *
     * @return list<string>
     */
    private function splitTxtIntoEscapedChunks(string $rawText, int $maxChunkBytes): array
    {
        $characters = preg_split('//u', $rawText, -1, PREG_SPLIT_NO_EMPTY);
        if (! is_array($characters) || $characters === []) {
            $characters = str_split($rawText);
        }

        $chunks = [];
        $currentChunk = '';
        $currentByteLength = 0;

        foreach ($characters as $char) {
            $escapedChar = match ($char) {
                '\\' => '\\\\',
                '"' => '\\"',
                default => $char,
            };

            $escapedBytes = strlen($escapedChar);

            if ($currentByteLength + $escapedBytes > $maxChunkBytes && $currentChunk !== '') {
                $chunks[] = $currentChunk;
                $currentChunk = $escapedChar;
                $currentByteLength = $escapedBytes;
            } else {
                $currentChunk .= $escapedChar;
                $currentByteLength += $escapedBytes;
            }
        }

        if ($currentChunk !== '') {
            $chunks[] = $currentChunk;
        }

        return $chunks;
    }

    private function assertNoSurroundingWhitespace(string $value, string $fieldLabel): void
    {
        if ($value !== '' && (preg_match('/^\s|\s$/', $value) === 1)) {
            throw new InvalidArgumentException("{$fieldLabel} cannot contain leading or trailing whitespace.");
        }
    }

    /**
     * @param  string|array<string, mixed>  $content
     */
    private function requireStringContent(string $type, string|array $content): string
    {
        if (is_array($content)) {
            return $this->extractStringField($content, ['content', 'value', 'target', 'ip'], $type);
        }

        return $content;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function extractIntField(array $data, string $key, string $type): int
    {
        if (! array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            throw new InvalidArgumentException("Missing required integer field \"{$key}\" for {$type} record.");
        }

        $val = $data[$key];
        if (is_int($val)) {
            return $val;
        }

        if (is_string($val) && preg_match('/^-?\d+$/', $val) === 1) {
            return (int) $val;
        }

        throw new InvalidArgumentException("Field \"{$key}\" for {$type} record must be an integer.");
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $candidateKeys
     */
    private function extractStringField(array $data, array $candidateKeys, string $type): string
    {
        foreach ($candidateKeys as $key) {
            if (array_key_exists($key, $data) && is_string($data[$key])) {
                return $data[$key];
            }
        }

        $firstKey = $candidateKeys[0] ?? 'content';

        throw new InvalidArgumentException("Missing required string field \"{$firstKey}\" for {$type} record.");
    }
}
