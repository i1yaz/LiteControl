<?php

declare(strict_types=1);

namespace App\Domains\Mail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailAccount extends Model
{
    protected $fillable = [
        'mail_domain_id',
        'local_part',
        'email',
        'password_hash',
        'quota_mb',
        'used_bytes',
        'active',
        'allow_imap',
        'allow_pop3',
        'allow_smtp',
    ];

    protected $hidden = [
        'password_hash',
    ];

    protected function casts(): array
    {
        return [
            'quota_mb' => 'integer',
            'used_bytes' => 'integer',
            'active' => 'boolean',
            'allow_imap' => 'boolean',
            'allow_pop3' => 'boolean',
            'allow_smtp' => 'boolean',
        ];
    }

    public function mailDomain(): BelongsTo
    {
        return $this->belongsTo(MailDomain::class);
    }
}
