<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Resume extends Model
{
    protected $table = 'resumes';
    protected $primaryKey = 'id';

    protected $fillable = [
        'student_id',
        'title',
        'template',
        'full_name',
        'professional_title',
        'summary',
        'experience',
        'education',
        'skills',
        'projects',
        'file_path',
        'is_public'
    ];

    protected $casts = [
        'experience' => 'array',
        'education' => 'array',
        'skills' => 'array',
        'projects' => 'array',
        'is_public' => 'boolean'
    ];

    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id');
    }
    public function analysis()
    {
        return $this->hasOne(ResumeAnalysis::class, 'resume_id');
    }

    public function applications()
    {
        return $this->hasMany(Application::class);
    }

}