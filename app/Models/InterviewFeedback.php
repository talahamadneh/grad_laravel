<?php 

namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class InterviewFeedback extends Model
{
    protected $table = 'interview_feedback';

    protected $fillable = [
        'interview_id',
        'technical_score',
        'communication_score',
        'notes',
        'final_decision',
    ];

    public function interview()
    {
        return $this->belongsTo(Interview::class);
    }
}
