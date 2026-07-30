<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResumeAnalysis extends Model
{
    protected $table = 'resume_analysis';

    protected $fillable = [
        'resume_id',
        'cv_score',
        'ats_score',
        'strengths',
        'weaknesses',
        'missing_skills',
        'recommendations',
    ];

    protected $casts = [
        'cv_score' => 'integer',
        'ats_score' => 'integer',
        'strengths' => 'array',
        'weaknesses' => 'array',
        'missing_skills' => 'array',
        'recommendations' => 'array',
    ];

    public function resume()
    {
        return $this->belongsTo(Resume::class, 'resume_id');
    }
}