<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HospitalResource extends Model
{
    use HasFactory;

    protected $primaryKey = 'resource_id';
    public $incrementing = true;

    protected $table = 'hospital_resources';

    // Disable timestamps since the table uses last_updated
    public $timestamps = false;

    protected $fillable = [
        'hospital_id',
        'resource_type',
        'quantity_total',
        'quantity_available',
        'total_cost',
        'cost_per_unit',
        'unit',
        'last_updated'
    ];

    protected $casts = [
        'last_updated' => 'datetime',
        'total_cost' => 'decimal:2',
        'cost_per_unit' => 'decimal:2'
    ];

    /**
     * Relationship with hospital
     */
    public function hospital()
    {
        return $this->belongsTo(Hospital::class, 'hospital_id', 'hospital_id');
    }

    /**
     * Relationship with batches
     */
    public function batches()
    {
        return $this->hasMany(ResourceBatch::class, 'resource_id', 'resource_id');
    }

    /**
     * Relationship with thresholds
     */
    public function threshold()
    {
        return $this->hasOne(ResourceThreshold::class, 'resource_id', 'resource_id');
    }

    /**
     * Override the create method to set last_updated
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->last_updated = now();
        });

        static::updating(function ($model) {
            $model->last_updated = now();
        });
    }
}