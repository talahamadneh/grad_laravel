<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Student extends Model
{
    protected $table = 'students';
    protected $primaryKey = 'StudentID';

    protected $fillable = [
        'UserID',
        'University',
        'Major',
        'GraduationYear',
        'Phone',
        'LinkedIn',
        'GitHub',
        'Portfolio',
        'Bio',
        'ProfileCompletion'
    ];
}
