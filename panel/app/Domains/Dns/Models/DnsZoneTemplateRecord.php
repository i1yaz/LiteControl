<?php

declare(strict_types=1);

namespace App\Domains\Dns\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DnsZoneTemplateRecord extends Model
{
    protected $table = 'dns_zone_template_records';

    protected $fillable = [
        'dns_zone_template_id',
        'name',
        'type',
        'ttl',
        'priority',
        'content',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'dns_zone_template_id' => 'integer',
            'ttl' => 'integer',
            'priority' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<DnsZoneTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(DnsZoneTemplate::class, 'dns_zone_template_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'name' => (string) $this->name,
            'type' => strtoupper((string) $this->type),
            'ttl' => (int) $this->ttl,
            'priority' => $this->priority !== null ? (int) $this->priority : null,
            'content' => (string) $this->content,
            'sort_order' => (int) $this->sort_order,
        ];
    }
}
