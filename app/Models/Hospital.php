<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Hospital extends Model
{
    use HasFactory;

    protected $primaryKey = 'hospital_id';
    public $incrementing = true;

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

    /**
     * Relationship with hospital_resources table
     */
    public function resources(): HasMany
    {
        return $this->hasMany(HospitalResource::class, 'hospital_id', 'hospital_id');
    }

    /**
     * Relationship with responders
     */
    public function responders(): HasMany
    {
        return $this->hasMany(Responder::class, 'hospital_id', 'hospital_id');
    }

    /**
     * Relationship with vehicles  
     */
    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'hospital_id', 'hospital_id');
    }
}