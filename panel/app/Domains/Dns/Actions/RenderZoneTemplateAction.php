<?php

declare(strict_types=1);

namespace App\Domains\Dns\Actions;

use App\Domains\Dns\Models\DnsZoneTemplate;
use App\Domains\Dns\Models\DnsZoneTemplateRecord;
use App\Domains\Dns\ValueObjects\RecordNormalizer;
use App\Domains\Dns\ValueObjects\ZoneTemplateRecordData;
use App\Domains\Dns\ValueObjects\ZoneTemplateVariables;

final class RenderZoneTemplateAction
{
    public const SUPPORTED_PLACEHOLDERS = [
        '{{DOMAIN}}',
        '{{IPV4}}',
        '{{IPV6}}',
        '{{NS1}}',
        '{{NS2}}',
    ];

    public function __construct(
        private readonly RecordNormalizer $recordNormalizer = new RecordNormalizer,
    ) {}

    /**
     * Deterministically expand placeholders ({{DOMAIN}}, {{IPV4}}, {{IPV6}}, {{NS1}}, {{NS2}})
     * and group template records into PowerDNS RRSet PATCH payloads.
     *
     * @param  DnsZoneTemplate|iterable<DnsZoneTemplateRecord|ZoneTemplateRecordData|array<string, mixed>>  $templateOrRecords
     * @return list<array{name: string, type: string, ttl: int, changetype: string, records: list<array{content: string, disabled: bool}>}>
     */
    public function handle(DnsZoneTemplate|iterable $templateOrRecords, ZoneTemplateVariables $variables): array
    {
        $rawRecords = $templateOrRecords instanceof DnsZoneTemplate
            ? ($templateOrRecords->relationLoaded('records') ? $templateOrRecords->records : $templateOrRecords->records()->get())
            : $templateOrRecords;

        $canonicalDomain = $variables->domain;
        $bareDomain = $variables->bareDomain();
        $grouped = [];

        foreach ($rawRecords as $record) {
            $normalized = $this->extractRecordTuple($record);
            $rawName = $normalized['name'];
            $type = $normalized['type'];
            $ttl = $normalized['ttl'];
            $priority = $normalized['priority'];
            $rawContent = $normalized['content'];

            if ($this->referencesMissingOptionalPlaceholder($rawName, $rawContent, $variables)) {
                continue;
            }

            $expandedName = $this->expandPlaceholders($rawName, $variables);
            $expandedContent = $this->expandPlaceholders($rawContent, $variables);

            $canonicalName = $this->normalizeRecordName($expandedName, $canonicalDomain, $bareDomain);
            $canonicalContent = $this->normalizeRecordContent(
                type: $type,
                content: $expandedContent,
                priority: $priority,
                canonicalDomain: $canonicalDomain,
                bareDomain: $bareDomain,
            );

            $groupKey = $canonicalName.'|'.$type;
            if (! isset($grouped[$groupKey])) {
                $grouped[$groupKey] = [
                    'name' => $canonicalName,
                    'type' => $type,
                    'ttl' => $ttl,
                    'changetype' => 'REPLACE',
                    'records' => [],
                    '_seen' => [],
                ];
            }

            if (! isset($grouped[$groupKey]['_seen'][$canonicalContent])) {
                $grouped[$groupKey]['_seen'][$canonicalContent] = true;
                $grouped[$groupKey]['records'][] = [
                    'content' => $canonicalContent,
                    'disabled' => false,
                ];
            }
        }

        $rrsets = [];
        foreach ($grouped as $group) {
            unset($group['_seen']);
            if ($group['records'] !== []) {
                $rrsets[] = $group;
            }
        }

        return $rrsets;
    }

    /**
     * Expand placeholders in a single string using the provided template variables.
     */
    public function expandPlaceholders(string $input, ZoneTemplateVariables $variables): string
    {
        $bareDomain = $variables->bareDomain();
        $canonicalDomain = $variables->domain;

        $replacements = [
            '{{DOMAIN}}.' => $canonicalDomain,
            '{{DOMAIN}}' => $bareDomain,
            '{{IPV4}}' => $variables->ipv4,
            '{{IPV6}}' => $variables->ipv6,
            '{{NS1}}.' => $variables->ns1,
            '{{NS1}}' => $variables->ns1,
            '{{NS2}}.' => $variables->ns2,
            '{{NS2}}' => $variables->ns2,
        ];

        return strtr($input, $replacements);
    }

    /**
     * @param  DnsZoneTemplateRecord|ZoneTemplateRecordData|array<string, mixed>  $record
     * @return array{name: string, type: string, ttl: int, priority: int|null, content: string}
     */
    private function extractRecordTuple(DnsZoneTemplateRecord|ZoneTemplateRecordData|array $record): array
    {
        if ($record instanceof DnsZoneTemplateRecord) {
            return [
                'name' => trim((string) $record->name),
                'type' => strtoupper(trim((string) $record->type)),
                'ttl' => max(60, (int) $record->ttl),
                'priority' => $record->priority !== null ? (int) $record->priority : null,
                'content' => trim((string) $record->content),
            ];
        }

        if ($record instanceof ZoneTemplateRecordData) {
            return [
                'name' => $record->name,
                'type' => $record->type,
                'ttl' => $record->ttl,
                'priority' => $record->priority,
                'content' => $record->content,
            ];
        }

        return [
            'name' => trim((string) ($record['name'] ?? '@')),
            'type' => strtoupper(trim((string) ($record['type'] ?? 'A'))),
            'ttl' => max(60, (int) ($record['ttl'] ?? 3600)),
            'priority' => isset($record['priority']) && $record['priority'] !== '' ? (int) $record['priority'] : null,
            'content' => trim((string) ($record['content'] ?? '')),
        ];
    }

    private function referencesMissingOptionalPlaceholder(
        string $rawName,
        string $rawContent,
        ZoneTemplateVariables $variables,
    ): bool {
        $combined = $rawName.' '.$rawContent;

        if (str_contains($combined, '{{IPV6}}') && $variables->ipv6 === '') {
            return true;
        }

        if (str_contains($combined, '{{IPV4}}') && $variables->ipv4 === '') {
            return true;
        }

        return false;
    }

    private function normalizeRecordName(string $expandedName, string $canonicalDomain, string $bareDomain): string
    {
        $trimmed = strtolower(trim($expandedName));
        if ($trimmed === '' || $trimmed === '@') {
            return $canonicalDomain;
        }

        $withoutDot = rtrim($trimmed, '.');
        if ($withoutDot === '' || $withoutDot === strtolower($bareDomain)) {
            return $canonicalDomain;
        }

        if (str_ends_with($withoutDot, '.'.strtolower($bareDomain))) {
            return $withoutDot.'.';
        }

        if (str_ends_with($trimmed, '.')) {
            return $withoutDot.'.';
        }

        return $withoutDot.'.'.$canonicalDomain;
    }

    private function normalizeRecordContent(
        string $type,
        string $content,
        ?int $priority,
        string $canonicalDomain,
        string $bareDomain,
    ): string {
        $trimmed = trim($content);

        return match ($type) {
            'A' => $this->recordNormalizer->normalizeARecord($trimmed),
            'AAAA' => $this->recordNormalizer->normalizeAaaaRecord($trimmed),
            'NS' => $this->recordNormalizer->normalizeNsRecord($trimmed, $canonicalDomain),
            'CNAME' => $this->recordNormalizer->normalizeCnameRecord($trimmed, $canonicalDomain),
            'PTR' => $this->recordNormalizer->normalizePtrRecord($trimmed, $canonicalDomain),
            'MX' => $this->recordNormalizer->normalizeMxFromContentString($trimmed, $priority ?? 10, $canonicalDomain),
            'SRV' => $this->recordNormalizer->normalizeSrvFromContentString($trimmed, $canonicalDomain),
            'CAA' => $this->recordNormalizer->normalizeCaaFromContentString($trimmed),
            'TXT' => $this->recordNormalizer->normalizeTxtRecord($trimmed),
            default => $trimmed,
        };
    }
}
