<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Assignment extends Model
{
    use HasFactory;
    protected $primaryKey = 'assignment_id';

    protected $fillable = [
        'request_id',
        'responder_id',
        'hospital_id',
        'vehicle_id',
        'status',
        'assigned_at',
        'completed_at',
        'notes'
    ];

    public $timestamps = false;
    public function request()
    {
        return $this->belongsTo(RequestEntry::class, 'request_id');
    }

    public function responder()
    {
        return $this->belongsTo(Responder::class, 'responder_id');
    }

    public function hospital()
    {
        return $this->belongsTo(Hospital::class, 'hospital_id');
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }
}
