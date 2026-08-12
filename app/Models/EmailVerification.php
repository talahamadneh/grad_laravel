<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailVerification extends Model
{
    protected $fillable = [
        'user_id',
        'code',
        'attempts',
        'expires_at',
        'locked_until',
        'verified_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'locked_until' => 'datetime',
        'verified_at' => 'datetime',
    ];
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}