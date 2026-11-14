<?php

namespace App\Domains\Shared\Contracts;

interface AuditLoggerInterface
{
    /**
     * Record an audit log entry.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function log(array $attributes): void;
}
