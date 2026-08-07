<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationSetting extends Model
{
    protected $fillable = [

        'user_id',

        // Student notifications
        'application_updates',
        'interview_notifications',
        'job_recommendations',
        'job_deadline_reminders',
        'messages',
        'profile_views',
        'resume_feedback',

        // Company notifications
        'company_applications',
        'company_messages',
        'company_matches',
        'company_deadlines',
        'company_interviews',
        'weekly_application_summary',

    ];


    protected $casts = [

        'application_updates' => 'boolean',
        'interview_notifications' => 'boolean',
        'job_recommendations' => 'boolean',
        'job_deadline_reminders' => 'boolean',
        'messages' => 'boolean',
        'profile_views' => 'boolean',
        'resume_feedback' => 'boolean',

        'company_applications' => 'boolean',
        'company_messages' => 'boolean',
        'company_matches' => 'boolean',
        'company_deadlines' => 'boolean',
        'company_interviews' => 'boolean',
        'weekly_application_summary' => 'boolean',

    ];


    public function user()
    {
        return $this->belongsTo(User::class);
    }
}