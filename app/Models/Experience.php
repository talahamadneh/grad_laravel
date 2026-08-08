<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Experience extends Model
{
    protected $table = 'experience';
    protected $fillable = [
        'student_id',
        'company',
        'position',
        'description',
        'start_date',
        'end_date',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class,'student_id'); 
    }

    public function getTitleAttribute()
    {
        return $this->position;
    }
}