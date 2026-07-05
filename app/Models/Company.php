<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $table = 'companies';
    protected $primaryKey = 'company_id';

    protected $fillable = [
        'user_id',
        'company_name',
        'Industry',
        'Location',
        'Website',
        'Description',
        'ProfileCompletion'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function jobPosts()
    {
        return $this->hasMany(JobPost::class);
    }   

}
