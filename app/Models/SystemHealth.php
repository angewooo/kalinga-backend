<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SystemHealth extends Model
{
    use HasFactory;
    protected $primaryKey = 'health_id';
    protected $fillable = ['component_name', 'status', 'response_time_ms', 'error_rate', 'last_checked'];
    public $timestamps = false;
    protected $table = 'system_health';

}
