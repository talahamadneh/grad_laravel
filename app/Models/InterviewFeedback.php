<?php 

namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class InterviewFeedback extends Model
{
    protected $table = 'interview_feedbacks';
    protected $primaryKey = 'id';

    protected $fillable = [
        'interview_id',
        'feedback',
        'rating',
    ];

    public function interview()
    {
        return $this->belongsTo(Interview::class);
    }
}