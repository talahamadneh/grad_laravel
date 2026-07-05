<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SavedJob extends Model
{
    protected $table = 'saved_jobs';

    public $timestamps = false;

    protected $fillable = [
        'student_id',
        'job_post_id'
    ];
}