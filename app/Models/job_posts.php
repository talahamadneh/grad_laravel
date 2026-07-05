<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Job extends Model
{

 protected $table = 'job_posts';

    protected $fillable = [
        'company_id',
        'title',
        'description',
        'salary',
        'employment_type',
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
    return $this->belongsToMany(Skill::class, 'job_skills');
}

public function applications()
{
    return $this->hasMany(Application::class);
}

public function savedByStudents()
{
    return $this->belongsToMany(Student::class, 'saved_jobs');
}

public function aiMatches()
{
    return $this->hasMany(ALJobMatching::class);
}
}
