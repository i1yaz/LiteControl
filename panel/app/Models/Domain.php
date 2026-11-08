<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Domain extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'domain',
        'type',
        'parent_domain_id',
        'document_root',
        'public_path',
        'php_version',
        'ssl_enabled',
        'ssl_expires_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'ssl_enabled' => 'boolean',
            'ssl_expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parentDomain(): BelongsTo
    {
        return $this->belongsTo(Domain::class, 'parent_domain_id');
    }

    public function subdomains(): HasMany
    {
        return $this->hasMany(Domain::class, 'parent_domain_id');
    }

    public function ftpAccounts(): HasMany
    {
        return $this->hasMany(FtpAccount::class);
    }
}

