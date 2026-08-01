<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyNote extends Model
{
    use HasFactory;

    protected $table = 'company_notes';

    protected $fillable = [
        'application_id',
        'company_id',
        'note',
    ];

    public function application()
    {
        return $this->belongsTo(Application::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}