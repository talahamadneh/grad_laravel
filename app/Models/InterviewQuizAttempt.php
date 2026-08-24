<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InterviewQuizAttempt extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_ABANDONED = 'abandoned';

    protected $fillable = [
        'student_id',
        'job_id',
        'questions',
        'answers',
        'score',
        'status',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'questions' => 'array',
        'answers' => 'array',
        'score' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function job()
    {
        return $this->belongsTo(JobPost::class, 'job_id');
    }
}
