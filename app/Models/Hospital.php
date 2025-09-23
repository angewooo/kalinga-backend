<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Hospital extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'address',
        'latitude',
        'longitude',
        'capacity',
        'current_load',
        'contact_number',
        'facility_type',
        'doh_classification',
        'status'
    ];

    public function resources()
    {
        return $this->hasMany(HospitalResource::class, 'hospital_id');
    }

    public function responders()
    {
        return $this->hasMany(Responder::class, 'hospital_id');
    }

    public function vehicles()
    {
        return $this->hasMany(Vehicle::class, 'hospital_id');
    }
}
