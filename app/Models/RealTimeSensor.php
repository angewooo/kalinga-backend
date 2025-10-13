<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RealTimeSensor extends Model
{
    use HasFactory;

    protected $fillable = [
        'sensor_type',
        'location',
        'status',
        'installed_at'
    ];
    protected $primaryKey = 'sensor_id';
    public $timestamps = false;

    public function readings()
    {
        return $this->hasMany(SensorReading::class, 'sensor_id');
    }
}
