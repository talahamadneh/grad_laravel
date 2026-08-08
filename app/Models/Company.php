<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Company extends Model
{
    protected $table = 'companies';

    protected $casts = [
        'values' => 'array',
        'benefits' => 'array',
        'is_verified' => 'boolean',
    ];

    public function getLogoAttribute($value)
    {
        if (empty($value)) {
            return null;
        }

        // If it's already a full URL, return as-is
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return $value;
        }

        return Storage::disk('public')->url($value);
    }

    public function getCoverImageAttribute($value)
    {
        if (empty($value)) {
            return null;
        }

        // If it's already a full URL, return as-is
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return $value;
        }

        return Storage::disk('public')->url($value);
    }
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
