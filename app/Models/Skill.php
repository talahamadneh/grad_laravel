<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Skill extends Model
{
    protected $table = 'skills';

    protected $fillable = [
        'name',
    ];

    public function students()
    {
        return $this->belongsToMany(Student::class, 'student_skills', 'skill_id', 'student_id');
    }

    public function jobPosts()
    {
        return $this->belongsToMany(JobPost::class, 'job_skills', 'skill_id', 'job_post_id');
    }
}
