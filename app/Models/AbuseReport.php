<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AbuseReport extends Model
{
    public const REPORTABLE_TYPES = [
        'Company',
        'JobPost',
        'Student',
        'Message',
    ];

    protected $fillable = [
        'reporter_id',
        'reportable_type',
        'reportable_id',
        'reason',
        'description',
        'status',
        'risk_level',
        'reviewed_by',
        'admin_note',
    ];

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
