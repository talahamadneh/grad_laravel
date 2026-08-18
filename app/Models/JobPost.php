<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Skill;

class JobPost extends Model
{
    protected $table = 'job_posts';

    protected $fillable = [
        'company_id',
        'title',
        'department',
        'description',
        'about',
        'responsibilities',
        'requirements',
        'benefits',
        'salary',
        'employment_type',
        'level',
        'work_mode',
        'location',
        'deadline',
        'vacancies',
        'required_major',
        'status',
        'quality_score',
        'moderation_issues',
        'moderation_recommendation',
        'moderation_note',
        'moderated_at',
        'reviewed_at',
    ];

    protected $casts = [
        'benefits' => 'array',
        'moderation_issues' => 'array',
        'deadline' => 'date',
        'moderated_at' => 'datetime',
        'reviewed_at' => 'datetime',
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
        return $this->hasMany(Application::class, 'job_post_id');
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
