<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AIJobMatching extends Model
{
    protected $table = 'ai_job_matches';

    protected $fillable = [
        'student_id',
        'job_post_id',
        'match_percentage',
        'missing_skills',
        'recommendations',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function jobPost()
    {
        return $this->belongsTo(JobPost::class);
    }
}
