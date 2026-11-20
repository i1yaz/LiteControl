<?php

declare(strict_types=1);

namespace App\Domains\Deployment\Models;

use App\Domains\WebServer\Models\Domain;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DomainDeployment extends Model
{
    protected $fillable = [
        'domain_id',
        'release_id',
        'commit_sha',
        'commit_message',
        'commit_author',
        'trigger_type',
        'status',
        'log_path',
        'error_summary',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(DomainDeploymentStep::class, 'deployment_id')->orderBy('sort_order');
    }
}
