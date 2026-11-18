<?php

declare(strict_types=1);

namespace App\Domains\Deployment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DomainDeploymentStep extends Model
{
    protected $fillable = [
        'deployment_id',
        'stage',
        'step_name',
        'status',
        'exit_code',
        'duration_ms',
        'log_excerpt',
        'sort_order',
    ];

    protected $attributes = [
        'status' => 'pending',
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'exit_code' => 'integer',
            'duration_ms' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(DomainDeployment::class, 'deployment_id');
    }
}
