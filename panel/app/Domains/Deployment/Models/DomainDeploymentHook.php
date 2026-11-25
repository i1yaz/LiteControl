<?php

declare(strict_types=1);

namespace App\Domains\Deployment\Models;

use App\Domains\Deployment\Enums\DeploymentHookSlot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DomainDeploymentHook extends Model
{
    protected $fillable = [
        'config_id',
        'name',
        'slot',
        'script',
        'sort_order',
        'is_active',
    ];

    protected $attributes = [
        'slot' => DeploymentHookSlot::AfterPurge->value,
        'sort_order' => 0,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'slot' => DeploymentHookSlot::class,
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function config(): BelongsTo
    {
        return $this->belongsTo(DomainDeploymentConfig::class, 'config_id');
    }
}
