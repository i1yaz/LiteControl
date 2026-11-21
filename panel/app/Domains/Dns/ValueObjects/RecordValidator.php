<?php

declare(strict_types=1);

namespace App\Domains\Dns\ValueObjects;

use InvalidArgumentException;

final readonly class RecordValidator
{
    public const MIN_TTL = 60;

    public const MAX_TTL = 604800;

    public const TTL_PRESETS = [60, 300, 900, 3600, 14400, 86400];

    public function __construct(
        private RecordNormalizer $normalizer = new RecordNormalizer,
        private ZoneNameNormalizer $zoneNameNormalizer = new ZoneNameNormalizer,
    ) {}

    /**
     * Validate a record type against PowerDNS v1 supported record types.
     *
     * @throws InvalidArgumentException
     */
    public function validateType(string $type): string
    {
        $this->assertNoSurroundingWhitespace($type, 'Record type');
        $upper = strtoupper($type);

        if (! in_array($upper, RecordNormalizer::SUPPORTED_TYPES, true)) {
            throw new InvalidArgumentException(
                sprintf('Unsupported DNS record type "%s". Supported types: %s.', $type, implode(', ', RecordNormalizer::SUPPORTED_TYPES))
            );
        }

        return $upper;
    }

    /**
     * Validate an RRSet TTL value (`60` to `604800` seconds).
     *
     * @throws InvalidArgumentException
     */
    public function validateTtl(int $ttl): int
    {
        if ($ttl < self::MIN_TTL || $ttl > self::MAX_TTL) {
            throw new InvalidArgumentException(
                sprintf('Record TTL must be between %d and %d seconds, got %d.', self::MIN_TTL, self::MAX_TTL, $ttl)
            );
        }

        return $ttl;
    }

    /**
     * Validate and normalize a single DNS record (name, type, content, and CNAME apex rule) within a zone.
     *
     * @param  string|array<string, mixed>  $content
     * @return array{name: string, type: string, content: string, disabled: bool}
     *
     * @throws InvalidArgumentException
     */
    public function validateAndNormalizeRecord(
        string $zoneName,
        string $name,
        string $type,
        string|array $content,
        bool $disabled = false,
    ): array {
        $canonicalZone = $this->zoneNameNormalizer->ensureTrailingDot($zoneName);
        $validatedType = $this->validateType($type);
        $canonicalName = $this->normalizer->normalizeRecordName($name, $canonicalZone);

        $this->assertCnameNotAtApex($canonicalZone, $canonicalName, $validatedType);
        $this->assertSoaAtApex($canonicalZone, $canonicalName, $validatedType);

        $canonicalContent = $this->normalizer->normalizeContent($validatedType, $content, $canonicalZone);

        return [
            'name' => $canonicalName,
            'type' => $validatedType,
            'content' => $canonicalContent,
            'disabled' => $disabled,
        ];
    }

    /**
     * Enforce RFC 1034 / RFC 1912 CNAME apex rule:
     * A CNAME record cannot exist at the zone apex (`@` / zone root) because the apex always owns `SOA` and `NS`.
     *
     * @throws InvalidArgumentException
     */
    public function assertCnameNotAtApex(string $zoneName, string $recordName, string $type): void
    {
        $validatedType = $this->validateType($type);
        if ($validatedType !== 'CNAME') {
            return;
        }

        $canonicalZone = $this->zoneNameNormalizer->ensureTrailingDot($zoneName);
        $canonicalName = $this->normalizer->normalizeRecordName($recordName, $canonicalZone);

        if ($canonicalName === $canonicalZone) {
            throw new InvalidArgumentException(
                sprintf('CNAME record is not permitted at the zone apex (%s) per RFC 1034/1912.', $canonicalZone)
            );
        }
    }

    /**
     * Enforce that SOA records only reside at the zone apex.
     *
     * @throws InvalidArgumentException
     */
    public function assertSoaAtApex(string $zoneName, string $recordName, string $type): void
    {
        $validatedType = $this->validateType($type);
        if ($validatedType !== 'SOA') {
            return;
        }

        $canonicalZone = $this->zoneNameNormalizer->ensureTrailingDot($zoneName);
        $canonicalName = $this->normalizer->normalizeRecordName($recordName, $canonicalZone);

        if ($canonicalName !== $canonicalZone) {
            throw new InvalidArgumentException(
                sprintf('SOA record can only be defined at the zone apex (%s), got "%s".', $canonicalZone, $canonicalName)
            );
        }
    }

    /**
     * Enforce RFC 1034 / RFC 1912 CNAME exclusivity for a single candidate record or RRSet against existing zone RRSets:
     * - Reject adding a `CNAME` at the zone apex
     * - Reject a `CNAME` RRSet containing more than one target record (RFC 2181 §10.1)
     * - Reject adding a `CNAME` on a `name` that already owns any other record type (`A`, `AAAA`, `TXT`, `MX`, etc.)
     * - Reject adding any non-`CNAME` record on a `name` where a `CNAME` already exists
     *
     * @param  list<array<string, mixed>>  $existingRrsets
     *
     * @throws InvalidArgumentException
     */
    public function assertNoCnameConflict(
        string $zoneName,
        string $recordName,
        string $type,
        array $existingRrsets = [],
        int $recordCount = 1,
    ): void {
        $canonicalZone = $this->zoneNameNormalizer->ensureTrailingDot($zoneName);
        $canonicalName = $this->normalizer->normalizeRecordName($recordName, $canonicalZone);
        $validatedType = $this->validateType($type);

        $this->assertCnameNotAtApex($canonicalZone, $canonicalName, $validatedType);

        if ($validatedType === 'CNAME' && $recordCount > 1) {
            throw new InvalidArgumentException(
                sprintf('CNAME RRSet for "%s" cannot contain multiple target records.', $canonicalName)
            );
        }

        $existingTypesForName = $this->collectActiveTypesForName($canonicalZone, $canonicalName, $existingRrsets);

        if ($validatedType === 'CNAME') {
            $conflictingTypes = array_values(array_diff($existingTypesForName, ['CNAME']));
            if ($conflictingTypes !== []) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Cannot add CNAME record for "%s" because %s record(s) already exist at that name (RFC 1034/1912 CNAME exclusivity).',
                        $canonicalName,
                        implode(', ', $conflictingTypes)
                    )
                );
            }

            return;
        }

        if (in_array('CNAME', $existingTypesForName, true)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Cannot add %s record for "%s" because a CNAME record already exists at that name (RFC 1034/1912 CNAME exclusivity).',
                    $validatedType,
                    $canonicalName
                )
            );
        }
    }

    /**
     * Validate a batch of staged RRSet operations (`REPLACE`, `DELETE`, `EXTEND`, `PRUNE`) against existing zone RRSets,
     * computing the effective post-batch zone state so that:
     * - Replacing/deleting a `CNAME` and adding `A`/`AAAA`/`TXT` on the same name in the same atomic `PATCH` succeeds.
     * - Any final state that leaves a `CNAME` alongside another record type (or multiple records inside a `CNAME`) is rejected.
     *
     * @param  list<array<string, mixed>>  $stagedRrsets
     * @param  list<array<string, mixed>>  $existingRrsets
     *
     * @throws InvalidArgumentException
     */
    public function assertBatchCnameExclusivity(
        string $zoneName,
        array $stagedRrsets,
        array $existingRrsets = [],
    ): void {
        $canonicalZone = $this->zoneNameNormalizer->ensureTrailingDot($zoneName);

        // Map: canonicalName => [type => recordCount]
        /** @var array<string, array<string, int>> $stateByName */
        $stateByName = [];

        foreach ($existingRrsets as $rrset) {
            if (! is_array($rrset)) {
                continue;
            }

            $rawName = isset($rrset['name']) && is_string($rrset['name']) ? $rrset['name'] : '';
            $rawType = isset($rrset['type']) && is_string($rrset['type']) ? strtoupper(trim($rrset['type'])) : '';
            if ($rawName === '' || $rawType === '') {
                continue;
            }

            $canonicalName = $this->normalizer->normalizeRecordName($rawName, $canonicalZone);
            $records = isset($rrset['records']) && is_array($rrset['records']) ? $rrset['records'] : [];
            $count = count($records);

            if ($count > 0) {
                $stateByName[$canonicalName][$rawType] = $count;
            }
        }

        foreach ($stagedRrsets as $operation) {
            if (! is_array($operation)) {
                continue;
            }

            $rawName = isset($operation['name']) && is_string($operation['name']) ? $operation['name'] : '';
            $rawType = isset($operation['type']) && is_string($operation['type']) ? $operation['type'] : '';
            $changetype = isset($operation['changetype']) && is_string($operation['changetype'])
                ? strtoupper(trim($operation['changetype']))
                : 'REPLACE';

            $canonicalName = $this->normalizer->normalizeRecordName($rawName, $canonicalZone);
            $validatedType = $this->validateType($rawType);
            $records = isset($operation['records']) && is_array($operation['records']) ? $operation['records'] : [];
            $opRecordCount = count($records);

            if ($changetype !== 'DELETE' && $changetype !== 'PRUNE') {
                $this->assertCnameNotAtApex($canonicalZone, $canonicalName, $validatedType);
            }

            switch ($changetype) {
                case 'DELETE':
                    unset($stateByName[$canonicalName][$validatedType]);
                    break;

                case 'PRUNE':
                    $current = $stateByName[$canonicalName][$validatedType] ?? 0;
                    $remaining = max(0, $current - max(1, $opRecordCount));
                    if ($remaining === 0) {
                        unset($stateByName[$canonicalName][$validatedType]);
                    } else {
                        $stateByName[$canonicalName][$validatedType] = $remaining;
                    }
                    break;

                case 'EXTEND':
                    $current = $stateByName[$canonicalName][$validatedType] ?? 0;
                    $stateByName[$canonicalName][$validatedType] = $current + max(1, $opRecordCount);
                    break;

                case 'REPLACE':
                default:
                    if ($opRecordCount === 0) {
                        unset($stateByName[$canonicalName][$validatedType]);
                    } else {
                        $stateByName[$canonicalName][$validatedType] = $opRecordCount;
                    }
                    break;
            }
        }

        foreach ($stateByName as $canonicalName => $typesMap) {
            if (! isset($typesMap['CNAME'])) {
                continue;
            }

            if ($canonicalName === $canonicalZone) {
                throw new InvalidArgumentException(
                    sprintf('CNAME record is not permitted at the zone apex (%s) per RFC 1034/1912.', $canonicalZone)
                );
            }

            if ($typesMap['CNAME'] > 1) {
                throw new InvalidArgumentException(
                    sprintf('CNAME RRSet for "%s" cannot contain multiple target records.', $canonicalName)
                );
            }

            $otherTypes = array_values(array_diff(array_keys($typesMap), ['CNAME']));
            if ($otherTypes !== []) {
                throw new InvalidArgumentException(
                    sprintf(
                        'CNAME exclusivity violation on "%s": CNAME cannot coexist with %s record(s).',
                        $canonicalName,
                        implode(', ', $otherTypes)
                    )
                );
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $existingRrsets
     * @return list<string>
     */
    private function collectActiveTypesForName(string $canonicalZone, string $canonicalName, array $existingRrsets): array
    {
        $types = [];

        foreach ($existingRrsets as $rrset) {
            if (! is_array($rrset)) {
                continue;
            }

            $rawName = isset($rrset['name']) && is_string($rrset['name']) ? $rrset['name'] : '';
            $rawType = isset($rrset['type']) && is_string($rrset['type']) ? strtoupper(trim($rrset['type'])) : '';
            if ($rawName === '' || $rawType === '') {
                continue;
            }

            $rrsetName = $this->normalizer->normalizeRecordName($rawName, $canonicalZone);
            if ($rrsetName !== $canonicalName) {
                continue;
            }

            $records = isset($rrset['records']) && is_array($rrset['records']) ? $rrset['records'] : null;
            if ($records !== null && $records === []) {
                continue;
            }

            if (! in_array($rawType, $types, true)) {
                $types[] = $rawType;
            }
        }

        return $types;
    }

    private function assertNoSurroundingWhitespace(string $value, string $fieldLabel): void
    {
        if ($value === '' || preg_match('/^\s|\s$/', $value) === 1) {
            throw new InvalidArgumentException("{$fieldLabel} cannot be empty or contain leading or trailing whitespace.");
        }
    }
}
