<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Student extends Model
{
    protected $table = 'students';
    protected $primaryKey = 'StudentID';

    protected $fillable = [
        'user_id',
        'University',
        'Major',
        'GraduationYear',
        'Phone',
        'LinkedIn',
        'GitHub',
        'Portfolio',
        'Bio',
        'ProfileCompletion'
    ];

    public function user()
{
    return $this->belongsTo(User::class);
}

public function resumes()
{
    return $this->hasMany(Resume::class);
}

public function education()
{
    return $this->hasMany(Education::class);
}

public function experience()
{
    return $this->hasMany(Experience::class);
}

public function projects()
{
    return $this->hasMany(Project::class);
}

public function certificates()
{
    return $this->hasMany(Certificate::class);
}

public function skills()
{
    return $this->belongsToMany(Skill::class, 'student_skills');
}

public function applications()
{
    return $this->hasMany(Application::class);
}

public function savedJobs()
{
    return $this->belongsToMany(JobPost::class, 'saved_jobs');
}

public function aiMatches()
{
    return $this->hasMany(AiJobMatch::class);
}
}
