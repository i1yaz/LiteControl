<?php

namespace App\Models;

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

