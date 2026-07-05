<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Resume extends Model
{
    protected $table = 'resumes';
    protected $primaryKey = 'id';

    protected $fillable = [
        'student_id',
        'file_path',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
    public function analysis()
{
    return $this->hasOne(ResumeAnalysis::class);
}

public function applications()
{
    return $this->hasMany(Application::class);
}

}