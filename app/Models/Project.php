<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    protected $fillable = [
        'student_id',
        'title',
        'description',
        'github_link',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class,'student_id');
    }
}
