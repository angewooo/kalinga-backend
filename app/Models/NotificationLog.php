<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationLog extends Model
{
    use HasFactory;
    protected $primaryKey = 'log_id';

    protected $fillable = ['notification_id', 'channel_id', 'status', 'sent_at'];
    public $timestamps = false;
    protected $table = 'notification_logs';
    public function notification()
    {
        return $this->belongsTo(Notification::class, 'notification_id');
    }

    public function channel()
    {
        return $this->belongsTo(NotificationChannel::class, 'channel_id');
    }
}
