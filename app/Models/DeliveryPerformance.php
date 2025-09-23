<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeliveryPerformance extends Model
{
    use HasFactory;

    protected $fillable = [
        'assignment_id',
        'delivery_time',
        'success_rate',
        'issues_reported'
    ];

    public function assignment()
    {
        return $this->belongsTo(Assignment::class, 'assignment_id');
    }
}
