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

    public function resume()
    {
        return $this->belongsTo(Resume::class);
    }
}
