<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Responder extends Model
{
    use HasFactory;

    protected $primaryKey = 'responder_id';
    protected $fillable = [
        'user_id',
        'hospital_id',
        'specialization',
        'status',
        'shift_start',
        'shift_end'
    ];

    public $timestamps = false;

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function hospital()
    {
        return $this->belongsTo(Hospital::class, 'hospital_id');
    }

    public function assignments()
    {
        return $this->hasMany(Assignment::class, 'responder_id');
    }
}
