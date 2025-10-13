<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\NotificationChannel;

class NotificationChannelSeeder extends Seeder
{
    public function run()
    {
        $channels = [
            [
                'user_id' => 1,
                'channel_type' => 'Email',
                'address' => 'admin@kalinga.com',
                'is_active' => true,
            ],
            [
                'user_id' => 2,
                'channel_type' => 'SMS',
                'address' => '+639171234567',
                'is_active' => true,
            ],
            [
                'user_id' => 3,
                'channel_type' => 'Push Notification',
                'address' => 'device_token_abc123',
                'is_active' => true,
            ],
            [
                'user_id' => 1,
                'channel_type' => 'Slack',
                'address' => 'slack_webhook_url_here',
                'is_active' => false,
            ],
            [
                'user_id' => 3,
                'channel_type' => 'Telegram',
                'address' => 'telegram_bot_id_5678',
                'is_active' => true,
            ],
        ];

        foreach ($channels as $channel) {
            NotificationChannel::create($channel);
        }
    }
}
