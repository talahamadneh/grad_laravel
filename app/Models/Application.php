<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Application extends Model
{
    use HasFactory;

    protected $table = 'applications';

    protected $primaryKey = 'id';

    protected $fillable = [
        'student_id',
        'job_post_id',
        'resume_id',
        'applied_at',
        'status',
        'match_score',
        'reviewed_at',
    ];

    protected $casts = [
        'applied_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'match_score' => 'decimal:2',
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

    public function notes()
    {
        return $this->hasMany(CompanyNote::class);
    }

    public function timeline()
    {
        return $this->hasMany(ApplicationStatusHistory::class)
            ->orderBy('changed_at');
    }
}