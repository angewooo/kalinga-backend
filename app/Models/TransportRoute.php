<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransportRoute extends Model
{
    use HasFactory;

    protected $primaryKey = 'transport_id';

    protected $fillable = ['start_location', 'end_location', 'distance', 'estimated_time'];
    public $timestamps = false;
}
