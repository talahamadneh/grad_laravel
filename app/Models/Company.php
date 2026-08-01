<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $table = 'companies';

    protected $casts = [
        'values' => 'array',
        'benefits' => 'array',
        'is_verified' => 'boolean',
    ];
    protected $fillable = [
        'user_id',
        'company_name',
        'industry',
        'description',
        'logo',
        'website',
        'phone',
        'location',
        'approval_status',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function jobPosts()
    {
        return $this->hasMany(JobPost::class);
    }

    public function notes()
    {
        return $this->hasMany(CompanyNote::class);
    }

}
