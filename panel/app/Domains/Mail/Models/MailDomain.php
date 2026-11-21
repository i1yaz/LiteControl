<?php

declare(strict_types=1);

namespace App\Domains\Mail\Models;

use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MailDomain extends Model
{
    protected $fillable = [
        'user_id',
        'domain',
        'ownership_source',
        'active',
        'default_quota_mb',
        'max_accounts',
        'dkim_enabled',
        'spam_filter_enabled',
        'spam_header_score',
        'spam_reject_score',
        'outbound_hourly_limit',
        'tls_sni_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'default_quota_mb' => 'integer',
            'max_accounts' => 'integer',
            'dkim_enabled' => 'boolean',
            'spam_filter_enabled' => 'boolean',
            'spam_header_score' => 'float',
            'spam_reject_score' => 'float',
            'outbound_hourly_limit' => 'integer',
            'tls_sni_synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(MailAccount::class);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(MailAlias::class);
    }

    public function dkimKeys(): HasMany
    {
        return $this->hasMany(MailDkimKey::class);
    }
}
