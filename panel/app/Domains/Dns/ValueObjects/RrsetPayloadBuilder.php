<?php

declare(strict_types=1);

namespace App\Domains\Dns\ValueObjects;

use InvalidArgumentException;

final readonly class RrsetPayloadBuilder
{
    public const ALLOWED_CHANGETYPES = ['REPLACE', 'DELETE', 'EXTEND', 'PRUNE'];

    public function __construct(
        private RecordNormalizer $normalizer = new RecordNormalizer,
        private RecordValidator $validator = new RecordValidator,
        private ZoneNameNormalizer $zoneNameNormalizer = new ZoneNameNormalizer,
    ) {}

    /**
     * Build a PowerDNS `REPLACE` RRSet payload (replaces all records and comments for a `name` + `type` atomically).
     *
     * @param  list<string|array<string, mixed>>  $records
     * @param  list<string|array<string, mixed>>  $comments
     * @return array{
     *     name: string,
     *     type: string,
     *     ttl: int,
     *     changetype: 'REPLACE',
     *     records: list<array{content: string, disabled: bool}>,
     *     comments?: list<array{content: string, account: string, modified_at?: int}>
     * }
     *
     * @throws InvalidArgumentException
     */
    public function buildReplace(
        string $zoneName,
        string $name,
        string $type,
        int $ttl,
        array $records,
        array $comments = [],
    ): array {
        $canonicalZone = $this->zoneNameNormalizer->ensureTrailingDot($zoneName);
        $validatedType = $this->validator->validateType($type);
        $validatedTtl = $this->validator->validateTtl($ttl);
        $canonicalName = $this->normalizer->normalizeRecordName($name, $canonicalZone);

        $this->validator->assertCnameNotAtApex($canonicalZone, $canonicalName, $validatedType);
        $this->validator->assertSoaAtApex($canonicalZone, $canonicalName, $validatedType);

        if ($records === []) {
            throw new InvalidArgumentException(
                sprintf('REPLACE RRSet payload for "%s" (%s) requires at least one record (use DELETE to remove an RRSet).', $canonicalName, $validatedType)
            );
        }

        $normalizedRecords = $this->normalizeRecordEntries($canonicalZone, $validatedType, $records);

        if ($validatedType === 'CNAME' && count($normalizedRecords) > 1) {
            throw new InvalidArgumentException(
                sprintf('CNAME RRSet for "%s" cannot contain more than one target record.', $canonicalName)
            );
        }

        if ($validatedType === 'SOA' && count($normalizedRecords) > 1) {
            throw new InvalidArgumentException(
                sprintf('SOA RRSet for "%s" cannot contain more than one record.', $canonicalName)
            );
        }

        $payload = [
            'name' => $canonicalName,
            'type' => $validatedType,
            'ttl' => $validatedTtl,
            'changetype' => 'REPLACE',
            'records' => $normalizedRecords,
        ];

        $normalizedComments = $this->normalizeComments($comments);
        if ($normalizedComments !== []) {
            $payload['comments'] = $normalizedComments;
        }

        return $payload;
    }

    /**
     * Build a PowerDNS `DELETE` RRSet payload.
     * Critical PowerDNS API invariant: omit `ttl` completely and pass empty `records` and `comments`.
     *
     * @return array{
     *     name: string,
     *     type: string,
     *     changetype: 'DELETE',
     *     records: array{},
     *     comments: array{}
     * }
     *
     * @throws InvalidArgumentException
     */
    public function buildDelete(
        string $zoneName,
        string $name,
        string $type,
    ): array {
        $canonicalZone = $this->zoneNameNormalizer->ensureTrailingDot($zoneName);
        $validatedType = $this->validator->validateType($type);
        $canonicalName = $this->normalizer->normalizeRecordName($name, $canonicalZone);

        if ($validatedType === 'SOA') {
            throw new InvalidArgumentException('Cannot DELETE the zone SOA RRSet.');
        }

        return [
            'name' => $canonicalName,
            'type' => $validatedType,
            'changetype' => 'DELETE',
            'records' => [],
            'comments' => [],
        ];
    }

    /**
     * Build a PowerDNS `EXTEND` RRSet payload (single-record quick-add).
     * Critical PowerDNS API invariant: `EXTEND` accepts strictly one record in `records` and empty `comments`.
     *
     * @param  string|array<string, mixed>  $record
     * @return array{
     *     name: string,
     *     type: string,
     *     ttl: int,
     *     changetype: 'EXTEND',
     *     records: list<array{content: string, disabled: bool}>,
     *     comments: array{}
     * }
     *
     * @throws InvalidArgumentException
     */
    public function buildExtend(
        string $zoneName,
        string $name,
        string $type,
        int $ttl,
        string|array $record,
        bool $disabled = false,
        array $comments = [],
    ): array {
        if ($comments !== []) {
            throw new InvalidArgumentException('PowerDNS EXTEND changetype requires empty comments.');
        }

        $canonicalZone = $this->zoneNameNormalizer->ensureTrailingDot($zoneName);
        $validatedType = $this->validator->validateType($type);
        $validatedTtl = $this->validator->validateTtl($ttl);
        $canonicalName = $this->normalizer->normalizeRecordName($name, $canonicalZone);

        $this->validator->assertCnameNotAtApex($canonicalZone, $canonicalName, $validatedType);
        $this->validator->assertSoaAtApex($canonicalZone, $canonicalName, $validatedType);

        $singleRecordEntry = $this->extractSingleRecordForExtendOrPrune('EXTEND', $canonicalZone, $validatedType, $record, $disabled);

        return [
            'name' => $canonicalName,
            'type' => $validatedType,
            'ttl' => $validatedTtl,
            'changetype' => 'EXTEND',
            'records' => [$singleRecordEntry],
            'comments' => [],
        ];
    }

    /**
     * Build a PowerDNS `PRUNE` RRSet payload (single-record quick-remove).
     * Critical PowerDNS API invariant: `PRUNE` accepts strictly one record in `records`, omits `ttl`, and passes empty `comments`.
     *
     * @param  string|array<string, mixed>  $record
     * @return array{
     *     name: string,
     *     type: string,
     *     changetype: 'PRUNE',
     *     records: list<array{content: string, disabled: bool}>,
     *     comments: array{}
     * }
     *
     * @throws InvalidArgumentException
     */
    public function buildPrune(
        string $zoneName,
        string $name,
        string $type,
        string|array $record,
        bool $disabled = false,
        array $comments = [],
    ): array {
        if ($comments !== []) {
            throw new InvalidArgumentException('PowerDNS PRUNE changetype requires empty comments.');
        }

        $canonicalZone = $this->zoneNameNormalizer->ensureTrailingDot($zoneName);
        $validatedType = $this->validator->validateType($type);
        $canonicalName = $this->normalizer->normalizeRecordName($name, $canonicalZone);

        $singleRecordEntry = $this->extractSingleRecordForExtendOrPrune('PRUNE', $canonicalZone, $validatedType, $record, $disabled);

        return [
            'name' => $canonicalName,
            'type' => $validatedType,
            'changetype' => 'PRUNE',
            'records' => [$singleRecordEntry],
            'comments' => [],
        ];
    }

    /**
     * Build a single PowerDNS RRSet payload from an operation descriptor array (`REPLACE`, `DELETE`, `EXTEND`, `PRUNE`).
     *
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    public function buildFromOperation(string $zoneName, array $operation): array
    {
        $changetype = isset($operation['changetype']) && is_string($operation['changetype'])
            ? strtoupper(trim($operation['changetype']))
            : 'REPLACE';

        if (! in_array($changetype, self::ALLOWED_CHANGETYPES, true)) {
            throw new InvalidArgumentException(
                sprintf('Unsupported PowerDNS RRSet changetype "%s". Allowed: %s.', $changetype, implode(', ', self::ALLOWED_CHANGETYPES))
            );
        }

        $name = isset($operation['name']) && is_string($operation['name']) ? $operation['name'] : '';
        $type = isset($operation['type']) && is_string($operation['type']) ? $operation['type'] : '';
        $comments = isset($operation['comments']) && is_array($operation['comments']) ? $operation['comments'] : [];

        if ($changetype === 'DELETE') {
            if (array_key_exists('ttl', $operation) && $operation['ttl'] !== null) {
                throw new InvalidArgumentException('PowerDNS DELETE changetype must omit the ttl field.');
            }

            if (isset($operation['records']) && is_array($operation['records']) && $operation['records'] !== []) {
                throw new InvalidArgumentException('PowerDNS DELETE changetype requires an empty records array.');
            }

            if ($comments !== []) {
                throw new InvalidArgumentException('PowerDNS DELETE changetype requires an empty comments array.');
            }

            return $this->buildDelete($zoneName, $name, $type);
        }

        $records = isset($operation['records']) && is_array($operation['records']) ? array_values($operation['records']) : [];

        if ($changetype === 'EXTEND') {
            if (count($records) !== 1) {
                throw new InvalidArgumentException('PowerDNS EXTEND changetype requires exactly one record.');
            }

            $ttl = $this->extractTtlFromOperation($operation);

            return $this->buildExtend(
                zoneName: $zoneName,
                name: $name,
                type: $type,
                ttl: $ttl,
                record: $records[0],
                comments: $comments,
            );
        }

        if ($changetype === 'PRUNE') {
            if (count($records) !== 1) {
                throw new InvalidArgumentException('PowerDNS PRUNE changetype requires exactly one record.');
            }

            return $this->buildPrune(
                zoneName: $zoneName,
                name: $name,
                type: $type,
                record: $records[0],
                comments: $comments,
            );
        }

        $ttl = $this->extractTtlFromOperation($operation);

        return $this->buildReplace(
            zoneName: $zoneName,
            name: $name,
            type: $type,
            ttl: $ttl,
            records: $records,
            comments: $comments,
        );
    }

    /**
     * Build and validate a full `PATCH /api/v1/servers/localhost/zones/{zone_id}` payload (`['rrsets' => [...]]`),
     * enforcing all record normalization, payload invariants, and CNAME exclusivity rules against `$existingRrsets`.
     *
     * @param  list<array<string, mixed>>  $operations
     * @param  list<array<string, mixed>>  $existingRrsets
     * @return array{rrsets: list<array<string, mixed>>}
     *
     * @throws InvalidArgumentException
     */
    public function buildBatchPatchPayload(
        string $zoneName,
        array $operations,
        array $existingRrsets = [],
    ): array {
        $canonicalZone = $this->zoneNameNormalizer->ensureTrailingDot($zoneName);

        if ($operations === []) {
            throw new InvalidArgumentException('Batch RRSet PATCH payload requires at least one RRSet operation.');
        }

        $builtRrsets = [];
        foreach ($operations as $operation) {
            if (! is_array($operation)) {
                throw new InvalidArgumentException('Each RRSet operation in a batch PATCH payload must be an array.');
            }

            $builtRrsets[] = $this->buildFromOperation($canonicalZone, $operation);
        }

        $this->validator->assertBatchCnameExclusivity($canonicalZone, $builtRrsets, $existingRrsets);

        return [
            'rrsets' => $builtRrsets,
        ];
    }

    /**
     * Normalize and deduplicate a list of record entries for an RRSet.
     *
     * @param  list<string|array<string, mixed>>  $records
     * @return list<array{content: string, disabled: bool}>
     */
    private function normalizeRecordEntries(string $canonicalZone, string $validatedType, array $records): array
    {
        $normalized = [];
        $seen = [];

        foreach ($records as $entry) {
            $parsed = $this->normalizeSingleRecordEntry($canonicalZone, $validatedType, $entry);
            $dedupKey = $parsed['content'];

            if (isset($seen[$dedupKey])) {
                throw new InvalidArgumentException(
                    sprintf('Duplicate %s record content "%s" inside the same RRSet.', $validatedType, $parsed['content'])
                );
            }

            $seen[$dedupKey] = true;
            $normalized[] = $parsed;
        }

        return $normalized;
    }

    /**
     * @param  string|array<string, mixed>  $entry
     * @return array{content: string, disabled: bool}
     */
    private function normalizeSingleRecordEntry(
        string $canonicalZone,
        string $validatedType,
        string|array $entry,
        bool $defaultDisabled = false,
    ): array {
        if (is_string($entry)) {
            return [
                'content' => $this->normalizer->normalizeContent($validatedType, $entry, $canonicalZone),
                'disabled' => $defaultDisabled,
            ];
        }

        if (isset($entry['records']) && is_array($entry['records'])) {
            throw new InvalidArgumentException('Record entry cannot be a nested records list.');
        }

        $disabled = array_key_exists('disabled', $entry) ? (bool) $entry['disabled'] : $defaultDisabled;

        // If structured fields (priority/weight/port/flags/tag/primary_ns) are provided on the entry array, pass the array directly
        if (
            array_key_exists('priority', $entry)
            || array_key_exists('weight', $entry)
            || array_key_exists('port', $entry)
            || array_key_exists('flags', $entry)
            || array_key_exists('tag', $entry)
            || array_key_exists('primary_ns', $entry)
            || array_key_exists('primaryNs', $entry)
            || array_key_exists('mname', $entry)
        ) {
            return [
                'content' => $this->normalizer->normalizeContent($validatedType, $entry, $canonicalZone),
                'disabled' => $disabled,
            ];
        }

        if (! isset($entry['content']) || ! is_string($entry['content'])) {
            throw new InvalidArgumentException("Each {$validatedType} record entry must include a string \"content\" value.");
        }

        return [
            'content' => $this->normalizer->normalizeContent($validatedType, $entry['content'], $canonicalZone),
            'disabled' => $disabled,
        ];
    }

    /**
     * Enforce that `EXTEND` and `PRUNE` receive a single record (rejecting multi-element lists passed by mistake).
     *
     * @param  string|array<string, mixed>  $record
     * @return array{content: string, disabled: bool}
     */
    private function extractSingleRecordForExtendOrPrune(
        string $changetype,
        string $canonicalZone,
        string $validatedType,
        string|array $record,
        bool $disabled,
    ): array {
        if (is_array($record) && array_is_list($record)) {
            if (count($record) !== 1) {
                throw new InvalidArgumentException("PowerDNS {$changetype} changetype accepts strictly one record.");
            }

            $first = $record[0];
            if (! is_string($first) && ! is_array($first)) {
                throw new InvalidArgumentException("Invalid record entry for PowerDNS {$changetype}.");
            }

            return $this->normalizeSingleRecordEntry($canonicalZone, $validatedType, $first, $disabled);
        }

        return $this->normalizeSingleRecordEntry($canonicalZone, $validatedType, $record, $disabled);
    }

    /**
     * Normalize optional RRSet comments (`[{ content, account, modified_at? }]`).
     *
     * @param  list<string|array<string, mixed>>  $comments
     * @return list<array{content: string, account: string, modified_at?: int}>
     */
    private function normalizeComments(array $comments): array
    {
        $normalized = [];

        foreach ($comments as $comment) {
            if (is_string($comment)) {
                $trimmed = trim($comment);
                if ($trimmed === '') {
                    continue;
                }

                $normalized[] = [
                    'content' => $trimmed,
                    'account' => 'litecontrol',
                ];

                continue;
            }

            if (! is_array($comment)) {
                throw new InvalidArgumentException('RRSet comment entry must be a string or an associative array.');
            }

            $content = isset($comment['content']) && is_string($comment['content']) ? trim($comment['content']) : '';
            if ($content === '') {
                continue;
            }

            $account = isset($comment['account']) && is_string($comment['account']) && trim($comment['account']) !== ''
                ? trim($comment['account'])
                : 'litecontrol';

            $entry = [
                'content' => $content,
                'account' => $account,
            ];

            if (isset($comment['modified_at']) && is_numeric($comment['modified_at'])) {
                $entry['modified_at'] = (int) $comment['modified_at'];
            }

            $normalized[] = $entry;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function extractTtlFromOperation(array $operation): int
    {
        if (! array_key_exists('ttl', $operation) || $operation['ttl'] === null || $operation['ttl'] === '') {
            throw new InvalidArgumentException('RRSet operation requires an integer ttl between 60 and 604800.');
        }

        if (is_int($operation['ttl'])) {
            return $operation['ttl'];
        }

        if (is_string($operation['ttl']) && ctype_digit($operation['ttl'])) {
            return (int) $operation['ttl'];
        }

        throw new InvalidArgumentException('RRSet ttl must be an integer.');
    }
}
