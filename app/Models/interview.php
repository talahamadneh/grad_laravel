<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Intereview extends Model
{
    protected $table = 'interviews';
    protected $primaryKey = 'InterviewID';

    protected $fillable = [
        'JobPostID',
        'StudentID',
        'InterviewDate',
        'InterviewTime',
        'InterviewLocation',
        'InterviewStatus'
    ];

    public function jobPost()
    {
        return $this->belongsTo(JobPost::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function application()
    {
        return $this->belongsTo(Application::class);
    }

    public function feedback()
    {
        return $this->hasOne(InterviewFeedback::class);
    }
}