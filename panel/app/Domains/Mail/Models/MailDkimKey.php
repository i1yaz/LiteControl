<?php

declare(strict_types=1);

namespace App\Domains\Mail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailDkimKey extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'mail_domain_id',
        'selector',
        'algorithm',
        'public_key_txt',
        'private_key_path',
        'key_sha256',
        'status',
        'dns_verified_at',
        'created_at',
        'rotated_at',
    ];

    protected function casts(): array
    {
        return [
            'dns_verified_at' => 'datetime',
            'created_at' => 'datetime',
            'rotated_at' => 'datetime',
        ];
    }

    public function mailDomain(): BelongsTo
    {
        return $this->belongsTo(MailDomain::class);
    }
}
