<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResponderDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'badge_number',
        'license_number',
        'availability_status',
        'shift_start',
        'shift_end'
    ];

    protected $primaryKey = 'responder_detail_id';

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
