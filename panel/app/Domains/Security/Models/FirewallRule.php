<?php

namespace App\Domains\Security\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FirewallRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'protocol',
        'port',
        'source_ip',
        'action',
    ];
}

