<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HospitalResource extends Model
{
    use HasFactory;

    protected $fillable = [
        'hospital_id',
        'resource_type',
        'quantity_total',
        'quantity_available',
        'total_cost',
        'cost_per_unit',
        'unit'
    ];

    public function hospital()
    {
        return $this->belongsTo(Hospital::class, 'hospital_id');
    }

    public function batches()
    {
        return $this->hasMany(ResourceBatch::class, 'resource_id');
    }

    public function thresholds()
    {
        return $this->hasMany(ResourceThreshold::class, 'resource_id');
    }

    public function historicalDemands()
    {
        return $this->hasMany(HistoricalDemand::class, 'resource_id');
    }
}
