<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Vehicle extends Model
{
    use HasFactory;

    protected $primaryKey = 'vehicle_id';

    protected $fillable = [
        'hospital_id',
        'vehicle_type',
        'plate_number',
        'status',
        'capacity'
    ];

    public $timestamps = false;

    public function hospital()
    {
        return $this->belongsTo(Hospital::class, 'hospital_id');
    }

    public function assignments()
    {
        return $this->hasMany(Assignment::class, 'vehicle_id');
    }
}
