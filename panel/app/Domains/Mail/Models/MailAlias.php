<?php

declare(strict_types=1);

namespace App\Domains\Mail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailAlias extends Model
{
    protected $fillable = [
        'mail_domain_id',
        'source_email',
        'destination_email',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'is_catch_all' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function mailDomain(): BelongsTo
    {
        return $this->belongsTo(MailDomain::class);
    }
}
