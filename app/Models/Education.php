<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Education extends Model
{
    protected $table = 'education'; 
    protected $fillable = [
        'student_id',
        'university',
        'degree',
        'major',
        'start_date',
        'end_date',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class,'student_id');
    }
}
