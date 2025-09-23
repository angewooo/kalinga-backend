<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResourceBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'resource_id',
        'batch_number',
        'manufacture_date',
        'expiry_date',
        'supplier_id',
        'cost_per_unit',
        'quantity_received',
        'quality_status'
    ];

    public function resource()
    {
        return $this->belongsTo(HospitalResource::class, 'resource_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }
}
