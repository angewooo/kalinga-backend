<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationChannel extends Model
{
    use HasFactory;
    protected $primaryKey = 'channel_id';
    protected $fillable = ['name', 'description'];
    public $timestamps = false;
    protected $table = 'notification_channels';
    
    public function logs()
    {
        return $this->hasMany(NotificationLog::class, 'channel_id');
    }
}
