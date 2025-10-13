<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserProfile extends Model
{
    use HasFactory;

    protected $primaryKey = 'profile_id';

    protected $fillable = [
        'user_id',
        'first_name',
        'last_name',
        'contact_number',
        'gender',
        'address',
        'home_address',
        'emergency_contact_name',
        'emergency_contact_number',
        'blood_type',
        'allergies',
        'medical_conditions'
    ];

    // Add default values for nullable fields
    protected $attributes = [
        'first_name' => 'Unknown',
        'last_name' => 'User',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}