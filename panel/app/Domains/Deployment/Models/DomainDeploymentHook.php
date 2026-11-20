<?php

declare(strict_types=1);

namespace App\Domains\Deployment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DomainDeploymentHook extends Model
{
    protected $fillable = [
        'config_id',
        'name',
        'stage',
        'position',
        'script',
        'sort_order',
        'is_active',
    ];

    protected $attributes = [
        'sort_order' => 0,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function config(): BelongsTo
    {
        return $this->belongsTo(DomainDeploymentConfig::class, 'config_id');
    }
}
