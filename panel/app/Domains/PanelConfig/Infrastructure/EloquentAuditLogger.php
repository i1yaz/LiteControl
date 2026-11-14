<?php

namespace App\Domains\PanelConfig\Infrastructure;

use App\Domains\PanelConfig\Models\AuditLog;
use App\Domains\Shared\Contracts\AuditLoggerInterface;

class EloquentAuditLogger implements AuditLoggerInterface
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function log(array $attributes): void
    {
        AuditLog::create($attributes);
    }
}
