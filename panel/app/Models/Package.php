<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Package extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'disk_quota_mb',
        'bandwidth_mb',
        'max_domains',
        'max_subdomains',
        'max_databases',
        'max_ftp_accounts',
        'max_email_accounts',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}

