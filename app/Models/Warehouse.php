<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    use HasFactory;

    protected $primaryKey = 'warehouse_id';
    protected $fillable = ['name', 'location', 'capacity'];

    public $timestamps = false;
    public function supplyOrders()
    {
        return $this->hasMany(SupplyOrder::class, 'warehouse_id');
    }

    public function allocations()
    {
        return $this->hasMany(ResourceAllocation::class, 'warehouse_id');
    }
}
