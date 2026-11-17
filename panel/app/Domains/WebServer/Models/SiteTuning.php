<?php

namespace App\Domains\WebServer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteTuning extends Model
{
    protected $fillable = [
        'domain_id',
        'max_conns',
        'init_timeout',
        'persist_conn',
        'pc_keep_alive_timeout',
        'mem_soft_limit_mb',
        'mem_hard_limit_mb',
        'proc_soft_limit',
        'proc_hard_limit',
        'php_memory_limit_mb',
        'php_max_execution_time',
        'php_max_input_time',
        'php_upload_max_filesize_mb',
        'php_post_max_size_mb',
        'php_max_input_vars',
        'php_display_errors',
        'sync_status',
        'last_error',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'persist_conn' => 'boolean',
            'php_display_errors' => 'boolean',
            'applied_at' => 'datetime',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
