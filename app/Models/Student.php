<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Model;
use App\Models\Skill;


class Student extends Model
{
    protected $fillable = [
    'user_id',
    'university',
    'major',
    'graduation_year',
    'phone',
    'linkedin',
    'github',
    'portfolio',
    'bio',
    'profile_completion',
    'avatar',
    'headline',
    'location',
    'gpa',
    'preferred_employment_type'
];

    
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function education()
    {
        return $this->hasMany(Education::class, 'student_id');
    }

    public function educations()
    {
        return $this->education();
    }

    public function experience()
    {
        return $this->hasMany(Experience::class, 'student_id');
    }

    public function experiences()
    {
        return $this->experience();
    }

    public function projects()
    {
        return $this->hasMany(Project::class, 'student_id');
    }

    public function certificates()
    {
        return $this->hasMany(Certificate::class, 'student_id');
    }

    public function skills()
    {
        return $this->belongsToMany(Skill::class, 'student_skills', 'student_id', 'skill_id');
    }

    public function resumes()
    {
        return $this->hasMany(Resume::class, 'student_id');
    }

    public function applications()
    {
        return $this->hasMany(Application::class, 'student_id');
    }

    public function savedJobs()
    {
        return $this->belongsToMany(JobPost::class, 'saved_jobs', 'student_id', 'job_post_id');
    }

    public function aiMatches()
    {
        return $this->hasMany(AiJobMatch::class, 'student_id');
    }

    // Accessors
    public function getGraduationAttribute()
    {
        return $this->graduation_year;
    }

    public function getCompletionAttribute()
    {
        return $this->profile_completion ?? 0;
    }

    public function getUnivAttribute()
    {
        return $this->university;
    }

    public function getTitleAttribute()
    {
        return $this->headline;
    }
}