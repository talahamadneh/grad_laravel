<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationSetting extends Model
{

    protected $fillable = [

        'user_id',
        'application_updates',
        'interview_notifications',
        'job_recommendations',
        'messages',
        'profile_views',
        'resume_feedback'

    ];


    protected $casts = [

        'application_updates'=>'boolean',
        'interview_notifications'=>'boolean',
        'job_recommendations'=>'boolean',
        'messages'=>'boolean',
        'profile_views'=>'boolean',
        'resume_feedback'=>'boolean',

    ];


    public function user()
    {
        return $this->belongsTo(User::class);
    }

}