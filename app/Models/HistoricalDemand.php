<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HistoricalDemand extends Model
{
    use HasFactory;

    protected $fillable = ['resource_id', 'demand_value', 'recorded_at'];

    public function resource()
    {
        return $this->belongsTo(HospitalResource::class, 'resource_id');
    }
}
