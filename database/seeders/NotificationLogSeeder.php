<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\NotificationLog;

class NotificationLogSeeder extends Seeder
{
    public function run(): void
    {
        $logs = [
            [
                'notif_id' => 1,
                'channel_id' => 1,
                'delivery_status' => 'sent',
                'sent_at' => now(),
            ],
            [
                'notif_id' => 2,
                'channel_id' => 2,
                'delivery_status' => 'delivered',
                'sent_at' => now(),
            ],
            [
                'notif_id' => 3,
                'channel_id' => 3,
                'delivery_status' => 'failed',
                'sent_at' => now(),
            ],
            [
                'notif_id' => 4,
                'channel_id' => 1,
                'delivery_status' => 'sent',
                'sent_at' => now(),
            ],
            [
                'notif_id' => 5,
                'channel_id' => 2,
                'delivery_status' => 'delivered',
                'sent_at' => now(),
            ],
        ];

        foreach ($logs as $log) {
            NotificationLog::create($log);
        }
    }
}
