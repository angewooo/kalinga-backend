<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Notification;

class NotificationSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            ['user_id' => 1, 'title' => 'New Request Received', 'message' => 'A new emergency request has been submitted.', 'type' => 'alert', 'status' => 'unread', 'priority_level' => 1, 'read_at' => null],
            ['user_id' => 2, 'title' => 'Stock Alert', 'message' => 'Oxygen supply below threshold in Hospital A.', 'type' => 'resource', 'status' => 'unread', 'priority_level' => 2, 'read_at' => null],
            ['user_id' => 3, 'title' => 'System Update', 'message' => 'The AI model has been retrained successfully.', 'type' => 'system', 'status' => 'read', 'priority_level' => 3, 'read_at' => now()],
            ['user_id' => 1, 'title' => 'Responder Assigned', 'message' => 'A responder has been assigned to your request.', 'type' => 'dispatch', 'status' => 'read', 'priority_level' => 2, 'read_at' => now()],
            ['user_id' => 2, 'title' => 'Performance Report', 'message' => 'System performance report is ready.', 'type' => 'report', 'status' => 'unread', 'priority_level' => 3, 'read_at' => null],
        ];

        foreach ($data as $item) {
            Notification::create($item);
        }
    }
}
