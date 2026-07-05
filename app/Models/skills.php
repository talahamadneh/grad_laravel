<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Skill extends Model
{
    protected $table = 'skills';
    protected $primaryKey = 'SkillID';

    protected $fillable = [
        'SkillName',
    ];

    public function students()
    {
        return $this->belongsToMany(Student::class, 'student_skills');
    }

    public function jobPosts()
    {
        return $this->belongsToMany(JobPost::class, 'job_skills');
    }
}