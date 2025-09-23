<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdminDetail extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'department', 'access_level'];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
