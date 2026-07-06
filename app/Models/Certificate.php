<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Certificate extends Model
{
    protected $fillable = [
        'student_id',
        'name',
        'organization',
        'issue_date',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class,'student_id');
    }
}
