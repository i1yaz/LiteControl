<?php

declare(strict_types=1);

namespace App\Domains\Dns\Queries;

use App\Domains\Dns\Contracts\PowerDnsClientInterface;
use App\Domains\Dns\ValueObjects\RecordNormalizer;
use App\Domains\Dns\ValueObjects\ZoneNameNormalizer;

final class GetPdnsZoneDetailQuery
{
    public function __construct(
        private readonly PowerDnsClientInterface $powerDnsClient,
        private readonly ZoneNameNormalizer $zoneNameNormalizer = new ZoneNameNormalizer,
        private readonly RecordNormalizer $recordNormalizer = new RecordNormalizer,
    ) {}

    /**
     * Retrieve a single PowerDNS zone and normalize its metadata, SOA settings, and RRSets for the Record Editor UI.
     *
     * @return array<string, mixed>
     */
    public function handle(string $zoneId): array
    {
        $canonicalZoneId = $this->zoneNameNormalizer->ensureTrailingDot($zoneId);
        $rawZone = $this->powerDnsClient->getZone($canonicalZoneId);

        $canonicalName = $this->zoneNameNormalizer->ensureTrailingDot(
            (string) ($rawZone['name'] ?? $rawZone['id'] ?? $canonicalZoneId)
        );
        $asciiWithoutDot = rtrim($canonicalName, '.');
        $displayUtf8 = $this->zoneNameNormalizer->toDisplayUtf8($canonicalName);
        $reverseType = $this->zoneNameNormalizer->reverseType($canonicalName);
        $isReverse = $reverseType !== null;
        $kind = $this->normalizeKind((string) ($rawZone['kind'] ?? 'Native'));

        $masters = [];
        if (isset($rawZone['masters']) && is_array($rawZone['masters'])) {
            foreach ($rawZone['masters'] as $master) {
                if (is_string($master) && trim($master) !== '') {
                    $masters[] = trim($master);
                }
            }
        }

        $rawRrsets = isset($rawZone['rrsets']) && is_array($rawZone['rrsets']) ? $rawZone['rrsets'] : [];
        $normalizedRrsets = [];
        $soaData = null;

        foreach ($rawRrsets as $rrset) {
            if (! is_array($rrset)) {
                continue;
            }

            $rawOwner = isset($rrset['name']) && is_string($rrset['name']) ? $rrset['name'] : '';
            $rawType = isset($rrset['type']) && is_string($rrset['type']) ? strtoupper(trim($rrset['type'])) : '';
            if ($rawOwner === '' || $rawType === '') {
                continue;
            }

            try {
                $ownerFqdn = $this->recordNormalizer->normalizeRecordName($rawOwner, $canonicalName);
            } catch (\InvalidArgumentException) {
                $ownerFqdn = rtrim(strtolower(trim($rawOwner)), '.').'.';
            }
            $ownerDisplayUtf8 = $this->recordNormalizer->toDisplayUtf8($ownerFqdn);
            $ownerAsciiBare = rtrim($ownerFqdn, '.');
            $isOwnerIdn = strtolower($ownerDisplayUtf8) !== strtolower($ownerAsciiBare);

            $relativeName = $this->toRelativeLabel($ownerFqdn, $canonicalName);
            $relativeDisplayName = $relativeName === '@'
                ? '@'
                : $this->toRelativeDisplayLabel($ownerDisplayUtf8, $displayUtf8);

            $ttl = isset($rrset['ttl']) && is_numeric($rrset['ttl']) ? (int) $rrset['ttl'] : 3600;
            $rawRecords = isset($rrset['records']) && is_array($rrset['records']) ? $rrset['records'] : [];
            $rawComments = isset($rrset['comments']) && is_array($rrset['comments']) ? $rrset['comments'] : [];

            $comments = [];
            foreach ($rawComments as $commentEntry) {
                if (! is_array($commentEntry)) {
                    continue;
                }
                $commentContent = isset($commentEntry['content']) && is_string($commentEntry['content'])
                    ? trim($commentEntry['content'])
                    : '';
                if ($commentContent === '') {
                    continue;
                }
                $comments[] = [
                    'content' => $commentContent,
                    'account' => isset($commentEntry['account']) && is_string($commentEntry['account']) && trim($commentEntry['account']) !== ''
                        ? trim($commentEntry['account'])
                        : 'litecontrol',
                    'modified_at' => isset($commentEntry['modified_at']) && is_numeric($commentEntry['modified_at'])
                        ? (int) $commentEntry['modified_at']
                        : null,
                ];
            }

            $records = [];
            foreach ($rawRecords as $recordEntry) {
                if (! is_array($recordEntry)) {
                    continue;
                }
                $content = isset($recordEntry['content']) && is_string($recordEntry['content'])
                    ? $recordEntry['content']
                    : '';
                if ($content === '') {
                    continue;
                }
                $disabled = (bool) ($recordEntry['disabled'] ?? false);
                $parsed = $this->parseStructuredRecordContent($rawType, $content);

                $records[] = [
                    'content' => $content,
                    'disabled' => $disabled,
                    'parsed' => $parsed,
                ];

                if ($rawType === 'SOA' && $soaData === null) {
                    $soaData = array_merge($parsed, [
                        'ttl' => $ttl,
                        'content' => $content,
                        'comment' => $comments[0]['content'] ?? '',
                    ]);
                }
            }

            $normalizedRrsets[] = [
                'id' => $ownerFqdn.'|'.$rawType,
                'name' => $ownerFqdn,
                'display_name' => $ownerDisplayUtf8,
                'relative_name' => $relativeName,
                'relative_display_name' => $relativeDisplayName,
                'is_apex' => $ownerFqdn === $canonicalName,
                'is_idn' => $isOwnerIdn,
                'type' => $rawType,
                'ttl' => $ttl,
                'records' => $records,
                'comments' => $comments,
                'primary_comment' => $comments[0]['content'] ?? '',
            ];
        }

        usort($normalizedRrsets, static function (array $a, array $b): int {
            $rank = static function (array $item): int {
                if ($item['type'] === 'SOA') {
                    return 0;
                }
                if ($item['is_apex'] && $item['type'] === 'NS') {
                    return 1;
                }
                if ($item['is_apex']) {
                    return 2;
                }

                return 3;
            };

            $rankDiff = $rank($a) <=> $rank($b);
            if ($rankDiff !== 0) {
                return $rankDiff;
            }

            $nameDiff = strcasecmp((string) $a['relative_name'], (string) $b['relative_name']);
            if ($nameDiff !== 0) {
                return $nameDiff;
            }

            return strcasecmp((string) $a['type'], (string) $b['type']);
        });

        $zoneSerial = (int) ($rawZone['serial'] ?? ($soaData['serial'] ?? 0));

        if ($soaData === null) {
            $soaData = [
                'primary_ns' => 'ns1.'.$canonicalName,
                'hostmaster_rname' => 'hostmaster.'.$canonicalName,
                'hostmaster_email' => 'hostmaster@'.$asciiWithoutDot,
                'serial' => $zoneSerial,
                'refresh' => 10800,
                'retry' => 3600,
                'expire' => 604800,
                'minimum' => 3600,
                'ttl' => 3600,
                'content' => sprintf('ns1.%s hostmaster.%s %d 10800 3600 604800 3600', $canonicalName, $canonicalName, $zoneSerial),
                'comment' => '',
            ];
        }

        return [
            'id' => (string) ($rawZone['id'] ?? $canonicalName),
            'name' => $canonicalName,
            'ascii_name' => $asciiWithoutDot,
            'display_name' => $displayUtf8,
            'is_idn' => strtolower($displayUtf8) !== strtolower($asciiWithoutDot),
            'kind' => $kind,
            'is_reverse' => $isReverse,
            'reverse_type' => $reverseType,
            'zone_category' => $isReverse ? 'reverse' : 'forward',
            'serial' => $zoneSerial,
            'notified_serial' => (int) ($rawZone['notified_serial'] ?? 0),
            'edited_serial' => (int) ($rawZone['edited_serial'] ?? 0),
            'dnssec' => (bool) ($rawZone['dnssec'] ?? false),
            'soa_edit_api' => (string) ($rawZone['soa_edit_api'] ?? 'DEFAULT'),
            'api_rectify' => (bool) ($rawZone['api_rectify'] ?? true),
            'masters' => $masters,
            'account' => (string) ($rawZone['account'] ?? ''),
            'last_check' => isset($rawZone['last_check']) ? (int) $rawZone['last_check'] : null,
            'soa' => $soaData,
            'rrsets' => $normalizedRrsets,
        ];
    }

    private function toRelativeLabel(string $ownerFqdn, string $canonicalZone): string
    {
        if (strcasecmp($ownerFqdn, $canonicalZone) === 0) {
            return '@';
        }

        $suffix = '.'.$canonicalZone;
        if (str_ends_with(strtolower($ownerFqdn), strtolower($suffix))) {
            return substr($ownerFqdn, 0, -strlen($suffix));
        }

        return rtrim($ownerFqdn, '.');
    }

    private function toRelativeDisplayLabel(string $ownerDisplay, string $zoneDisplay): string
    {
        if (strcasecmp($ownerDisplay, $zoneDisplay) === 0) {
            return '@';
        }

        $suffix = '.'.$zoneDisplay;
        if (str_ends_with(strtolower($ownerDisplay), strtolower($suffix))) {
            return substr($ownerDisplay, 0, -strlen($suffix));
        }

        return $ownerDisplay;
    }

    /**
     * Parse record content into structured fields for type-aware UI editing (`MX`, `SRV`, `CAA`, `TXT`, `SOA`).
     *
     * @return array<string, mixed>
     */
    private function parseStructuredRecordContent(string $type, string $content): array
    {
        if ($type === 'MX') {
            if (preg_match('/^(\d+)\s+(\S+)$/', trim($content), $matches) === 1) {
                return [
                    'priority' => (int) $matches[1],
                    'target' => $matches[2],
                ];
            }

            return [
                'priority' => 10,
                'target' => trim($content),
            ];
        }

        if ($type === 'SRV') {
            if (preg_match('/^(\d+)\s+(\d+)\s+(\d+)\s+(\S+)$/', trim($content), $matches) === 1) {
                return [
                    'priority' => (int) $matches[1],
                    'weight' => (int) $matches[2],
                    'port' => (int) $matches[3],
                    'target' => $matches[4],
                ];
            }

            return [
                'priority' => 10,
                'weight' => 10,
                'port' => 443,
                'target' => trim($content),
            ];
        }

        if ($type === 'CAA') {
            if (preg_match('/^(\d+)\s+([A-Za-z0-9]+)\s+(.+)$/s', trim($content), $matches) === 1) {
                $rawVal = trim($matches[3]);
                if (strlen($rawVal) >= 2 && str_starts_with($rawVal, '"') && str_ends_with($rawVal, '"')) {
                    $rawVal = substr($rawVal, 1, -1);
                    $rawVal = str_replace(['\\"', '\\\\'], ['"', '\\'], $rawVal);
                }

                return [
                    'flags' => (int) $matches[1],
                    'tag' => strtolower($matches[2]),
                    'value' => $rawVal,
                ];
            }

            return [
                'flags' => 0,
                'tag' => 'issue',
                'value' => trim($content, '" '),
            ];
        }

        if ($type === 'TXT') {
            $chunks = [];
            if (preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/s', $content, $matches) > 0) {
                foreach ($matches[1] as $escapedChunk) {
                    $chunks[] = preg_replace_callback(
                        '/\\\\(.)/s',
                        static fn (array $m): string => $m[1],
                        $escapedChunk
                    ) ?? $escapedChunk;
                }
            }

            $rawText = $chunks !== [] ? implode('', $chunks) : $content;

            return [
                'raw_text' => $rawText,
                'chunk_count' => max(1, count($chunks)),
                'byte_length' => strlen($rawText),
            ];
        }

        if ($type === 'SOA') {
            $parts = preg_split('/\s+/', trim($content));
            if (is_array($parts) && count($parts) >= 7) {
                $rname = $parts[1];

                return [
                    'primary_ns' => $parts[0],
                    'hostmaster_rname' => $rname,
                    'hostmaster_email' => $this->rnameToEmail($rname),
                    'serial' => (int) $parts[2],
                    'refresh' => (int) $parts[3],
                    'retry' => (int) $parts[4],
                    'expire' => (int) $parts[5],
                    'minimum' => (int) $parts[6],
                ];
            }
        }

        return [
            'value' => $content,
        ];
    }

    /**
     * Convert an SOA RNAME (`hostmaster.example.com.` or `first\.last.example.com.`) to a readable email address.
     */
    private function rnameToEmail(string $rname): string
    {
        $trimmed = rtrim($rname, '.');
        if ($trimmed === '' || str_contains($trimmed, '@')) {
            return $trimmed;
        }

        $placeholder = '__ESCAPED_DOT__';
        $masked = str_replace('\\.', $placeholder, $trimmed);
        $firstDotPos = strpos($masked, '.');
        if ($firstDotPos === false) {
            return str_replace($placeholder, '.', $masked);
        }

        $localPart = str_replace($placeholder, '.', substr($masked, 0, $firstDotPos));
        $domainPart = str_replace($placeholder, '.', substr($masked, $firstDotPos + 1));

        return $localPart.'@'.$domainPart;
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
