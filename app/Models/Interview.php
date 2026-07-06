<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Interview extends Model
{
    protected $table = 'interviews';

    protected $fillable = [
        'application_id',
        'interview_date',
        'type',
        'meeting_link',
        'status',
    ];

    public function application()
    {
        return $this->belongsTo(Application::class);
    }

    public function feedback()
    {
        return $this->hasOne(InterviewFeedback::class);
    }
}
