<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ZohoToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'access_token',
        'refresh_token',
        'expires_at',
        'api_domain',
        'token_type',
        'account_id',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];
}


