<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SensorReading extends Model
{
    use HasFactory;

    protected $fillable = [
        'sensor_id',
        'reading_value',
        'recorded_at'
    ];
    protected $primaryKey = 'reading_id';
    public $timestamps = false;
    public function sensor()
    {
        return $this->belongsTo(RealTimeSensor::class, 'sensor_id');
    }
}
