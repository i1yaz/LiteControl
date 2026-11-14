<?php

namespace App\Domains\Database\Models;

use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DatabaseUser extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'database_id',
        'db_username',
        'host',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function database(): BelongsTo
    {
        return $this->belongsTo(Database::class);
    }
}
