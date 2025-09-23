<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'home_address',
        'emergency_contact_name',
        'emergency_contact_number',
        'blood_type',
        'allergies',
        'medical_conditions'
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
