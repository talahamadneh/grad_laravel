<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Application extends Model
{
    protected $table = 'applications';
    protected $primaryKey = 'id';

    protected $fillable = [
        'student_id',
        'job_post_id',
        'status',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function jobPost()
    {
        return $this->belongsTo(JobPost::class);
    }
    public function resume()
    {
        return $this->belongsTo(Resume::class);
    }

    public function interview()
    {
        return $this->hasOne(Interview::class);
    }
}