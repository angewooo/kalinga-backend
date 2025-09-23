<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationChannel extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description'];

    public function logs()
    {
        return $this->hasMany(NotificationLog::class, 'channel_id');
    }
}
