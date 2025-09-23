<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResourceThreshold extends Model
{
    use HasFactory;

    protected $fillable = [
        'resource_id',
        'min_level',
        'max_level',
        'alert_triggered'
    ];

    public function resource()
    {
        return $this->belongsTo(HospitalResource::class, 'resource_id');
    }
}
