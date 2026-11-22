<?php

declare(strict_types=1);

namespace App\Domains\Dns\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DnsZoneTemplate extends Model
{
    protected $table = 'dns_zone_templates';

    public const DEFAULT_TEMPLATE_NAME = 'Standard Web & Mail';

    protected $fillable = [
        'name',
        'description',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    /**
     * Ensure the default "Standard Web & Mail" zone template exists with its standard records.
     */
    public static function ensureDefaultSeeded(): self
    {
        $hasDefault = self::query()->where('is_default', true)->exists();

        /** @var self $template */
        $template = self::query()->firstOrCreate(
            ['name' => self::DEFAULT_TEMPLATE_NAME],
            [
                'description' => 'Default web (A/AAAA), authoritative nameservers (NS), mail exchange (MX), and SPF (TXT) records.',
                'is_default' => ! $hasDefault,
            ]
        );

        if ($template->records()->count() === 0) {
            foreach (self::defaultRecordsPayload() as $index => $record) {
                $template->records()->create([
                    'name' => $record['name'],
                    'type' => $record['type'],
                    'ttl' => $record['ttl'],
                    'priority' => $record['priority'],
                    'content' => $record['content'],
                    'sort_order' => $index,
                ]);
            }
        }

        return $template->load('records');
    }

    /**
     * @return list<array{name: string, type: string, ttl: int, priority: int|null, content: string}>
     */
    public static function defaultRecordsPayload(): array
    {
        return [
            [
                'name' => '@',
                'type' => 'NS',
                'ttl' => 3600,
                'priority' => null,
                'content' => '{{NS1}}',
            ],
            [
                'name' => '@',
                'type' => 'NS',
                'ttl' => 3600,
                'priority' => null,
                'content' => '{{NS2}}',
            ],
            [
                'name' => '@',
                'type' => 'A',
                'ttl' => 3600,
                'priority' => null,
                'content' => '{{IPV4}}',
            ],
            [
                'name' => 'www',
                'type' => 'A',
                'ttl' => 3600,
                'priority' => null,
                'content' => '{{IPV4}}',
            ],
            [
                'name' => 'mail',
                'type' => 'A',
                'ttl' => 3600,
                'priority' => null,
                'content' => '{{IPV4}}',
            ],
            [
                'name' => '@',
                'type' => 'AAAA',
                'ttl' => 3600,
                'priority' => null,
                'content' => '{{IPV6}}',
            ],
            [
                'name' => 'www',
                'type' => 'AAAA',
                'ttl' => 3600,
                'priority' => null,
                'content' => '{{IPV6}}',
            ],
            [
                'name' => 'mail',
                'type' => 'AAAA',
                'ttl' => 3600,
                'priority' => null,
                'content' => '{{IPV6}}',
            ],
            [
                'name' => '@',
                'type' => 'MX',
                'ttl' => 3600,
                'priority' => 10,
                'content' => 'mail.{{DOMAIN}}.',
            ],
            [
                'name' => '@',
                'type' => 'TXT',
                'ttl' => 3600,
                'priority' => null,
                'content' => '"v=spf1 a mx ip4:{{IPV4}} ~all"',
            ],
        ];
    }

    /**
     * @return HasMany<DnsZoneTemplateRecord, $this>
     */
    public function records(): HasMany
    {
        return $this->hasMany(DnsZoneTemplateRecord::class, 'dns_zone_template_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        $records = $this->relationLoaded('records')
            ? $this->records
            : $this->records()->get();

        return [
            'id' => $this->id,
            'name' => (string) $this->name,
            'description' => (string) ($this->description ?? ''),
            'is_default' => (bool) $this->is_default,
            'records' => $records->map(static fn (DnsZoneTemplateRecord $r): array => $r->toPublicArray())->values()->all(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
