<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SystemPerformance extends Model
{
    use HasFactory;

    protected $fillable = ['metric_name', 'metric_value', 'recorded_at'];
}
