<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryLog extends Model
{
    use HasFactory;

    protected $primaryKey = 'log_id';

    protected $fillable = [
        'resource_id',
        'change_type',
        'quantity_changed',
        'notes',
        'logged_at'
    ];
    public $timestamps = false;

    public function resource()
    {
        return $this->belongsTo(HospitalResource::class, 'resource_id');
    }
}
