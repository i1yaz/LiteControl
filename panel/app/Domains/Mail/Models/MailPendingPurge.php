<?php

declare(strict_types=1);

namespace App\Domains\Mail\Models;

use Illuminate\Database\Eloquent\Model;

class MailPendingPurge extends Model
{
    protected $fillable = [
        'entity_type',
        'domain',
        'local_part',
        'email',
        'status',
        'attempts',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
        ];
    }
}
