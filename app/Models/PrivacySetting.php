<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class PrivacySetting extends Model
{

    protected $fillable = [

        'user_id',
        'profile_visibility',
        'contact_visibility',
        'ai_resume_analysis',
        'ai_candidate_matching'

    ];


    protected $casts = [

        'profile_visibility' => 'boolean',
        'contact_visibility' => 'boolean',
        'ai_resume_analysis' => 'boolean',
        'ai_candidate_matching' => 'boolean'

    ];


    public function user()
    {
        return $this->belongsTo(User::class);
    }

}