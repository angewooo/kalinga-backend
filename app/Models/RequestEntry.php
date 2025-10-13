<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RequestEntry extends Model
{
    use HasFactory;

    protected $primaryKey = 'request_id';

    protected $table = 'requests';

    protected $fillable = [
        'citizen_name',
        'citizen_contact',
        'incident_type',
        'severity_level',
        'latitude',
        'longitude',
        'address',
        'description',
        'status',
        'resolved_at'
    ];

    public function assignments()
    {
        return $this->hasMany(Assignment::class, 'request_id');
    }
}
