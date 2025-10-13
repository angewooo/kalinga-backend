<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    use HasFactory;

    protected $primaryKey = 'notif_id';
    protected $fillable = ['user_id', 'title', 'message', 'status'];
    public $timestamps = false;
    protected $table = 'notifications';

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function logs()
    {
        return $this->hasMany(NotificationLog::class, 'notification_id');
    }
}
