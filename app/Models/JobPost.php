<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobPost extends Model
{
    protected $table = 'job_posts';

    protected $fillable = [
        'company_id',
        'title',
        'description',
        'about',
        'responsibilities',
        'requirements',
        'salary',
        'employment_type',
        'work_mode',
        'location',
        'deadline',
        'vacancies',
        'status'
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function skills()
    {
        return $this->belongsToMany(Skill::class, 'job_skills', 'job_post_id', 'skill_id');
    }

    public function applications()
    {
        return $this->hasMany(Application::class);
    }

    public function savedByStudents()
    {
        return $this->belongsToMany(Student::class, 'saved_jobs', 'job_post_id', 'student_id');
    }

    public function aiMatches()
    {
        return $this->hasMany(AIJobMatching::class);
    }

    public function savedBy()
    {
        return $this->hasMany(SavedJob::class);
    }
}
