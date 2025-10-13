<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResourceAllocation extends Model
{
    use HasFactory;

    protected $primaryKey = 'allocation_id';

    protected $fillable = ['resource_id', 'warehouse_id', 'allocated_quantity', 'allocated_at'];
    public $timestamps = false;
    public function resource()
    {
        return $this->belongsTo(HospitalResource::class, 'resource_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }
}
