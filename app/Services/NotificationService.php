<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class NotificationService
{
    /**
     * Send notification to user(s)
     * 
     * @param array $data
     * @return Notification
     */
    public function sendNotification(array $data)
    {
        DB::beginTransaction();
        
        try {
            // Validate required fields
            $this->validateNotificationData($data);

            // Create notification record
            $notification = Notification::create([
                'user_id' => $data['user_id'] ?? null,
                'type' => $data['type'],
                'title' => $data['title'],
                'message' => $data['message'],
                'priority' => $data['priority'] ?? 'normal',
                'data' => json_encode($data['additional_data'] ?? []),
                'read_at' => null,
                'sent_at' => now()
            ]);

            // Send through specified channels
            $channels = $data['channels'] ?? ['in_app'];
            foreach ($channels as $channelName) {
                $this->sendThroughChannel($notification, $channelName);
            }

            // If user_id is null, it's a broadcast notification
            if (!$data['user_id'] && isset($data['recipient_roles'])) {
                $this->broadcastToRoles($notification, $data['recipient_roles']);
            }

            DB::commit();

            Log::info("Notification sent successfully", [
                'notification_id' => $notification->id,
                'type' => $data['type']
            ]);

            return $notification;

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Notification sending failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Send notification through specific channel
     * 
     * @param Notification $notification
     * @param string $channelName
     */
    private function sendThroughChannel(Notification $notification, string $channelName)
    {
        try {
            // Get or create channel record
            $channel = NotificationChannel::firstOrCreate(
                ['channel_name' => $channelName],
                [
                    'description' => ucfirst($channelName) . ' notification channel',
                    'is_active' => true
                ]
            );

            // Log the notification
            $log = NotificationLog::create([
                'notification_id' => $notification->id,
                'notification_channel_id' => $channel->id,
                'recipient_id' => $notification->user_id,
                'status' => 'pending',
                'sent_at' => now(),
                'delivered_at' => null,
                'error_message' => null
            ]);

            // Simulate sending through different channels
            $success = match($channelName) {
                'email' => $this->sendEmail($notification),
                'sms' => $this->sendSMS($notification),
                'push' => $this->sendPushNotification($notification),
                'in_app' => true, // In-app is always successful (stored in DB)
                default => false
            };

            // Update log status
            $log->update([
                'status' => $success ? 'delivered' : 'failed',
                'delivered_at' => $success ? now() : null,
                'error_message' => $success ? null : 'Failed to send through ' . $channelName
            ]);

        } catch (Exception $e) {
            Log::error("Channel sending failed: " . $e->getMessage());
        }
    }

    /**
     * Broadcast notification to users with specific roles
     * 
     * @param Notification $notification
     * @param array $roles
     */
    private function broadcastToRoles(Notification $notification, array $roles)
    {
        $users = User::role($roles)->get();

        foreach ($users as $user) {
            Notification::create([
                'user_id' => $user->id,
                'type' => $notification->type,
                'title' => $notification->title,
                'message' => $notification->message,
                'priority' => $notification->priority,
                'data' => $notification->data,
                'read_at' => null,
                'sent_at' => now()
            ]);
        }
    }

    /**
     * Mark notification as read
     * 
     * @param int $notificationId
     * @param int $userId
     * @return bool
     */
    public function markAsRead(int $notificationId, int $userId)
    {
        try {
            $notification = Notification::where('id', $notificationId)
                ->where('user_id', $userId)
                ->whereNull('read_at')
                ->first();

            if (!$notification) {
                return false;
            }

            $notification->update([
                'read_at' => now()
            ]);

            Log::info("Notification marked as read", [
                'notification_id' => $notificationId,
                'user_id' => $userId
            ]);

            return true;

        } catch (Exception $e) {
            Log::error("Mark as read failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Mark all notifications as read for a user
     * 
     * @param int $userId
     * @return int Count of marked notifications
     */
    public function markAllAsRead(int $userId)
    {
        try {
            $count = Notification::where('user_id', $userId)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);

            Log::info("All notifications marked as read", [
                'user_id' => $userId,
                'count' => $count
            ]);

            return $count;

        } catch (Exception $e) {
            Log::error("Mark all as read failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get unread notifications for user
     * 
     * @param int $userId
     * @param array $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getUnreadNotifications(int $userId, array $filters = [])
    {
        try {
            $query = Notification::where('user_id', $userId)
                ->whereNull('read_at')
                ->orderBy('sent_at', 'desc');

            if (isset($filters['type'])) {
                $query->where('type', $filters['type']);
            }

            if (isset($filters['priority'])) {
                $query->where('priority', $filters['priority']);
            }

            return $query->get();

        } catch (Exception $e) {
            Log::error("Get unread notifications failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get all notifications for user with pagination
     * 
     * @param int $userId
     * @param int $perPage
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getUserNotifications(int $userId, int $perPage = 15)
    {
        return Notification::where('user_id', $userId)
            ->orderBy('sent_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Delete old notifications
     * 
     * @param int $daysOld
     * @return int Count of deleted notifications
     */
    public function deleteOldNotifications(int $daysOld = 30)
    {
        try {
            $count = Notification::where('sent_at', '<', now()->subDays($daysOld))
                ->whereNotNull('read_at')
                ->delete();

            Log::info("Old notifications deleted", ['count' => $count]);

            return $count;

        } catch (Exception $e) {
            Log::error("Delete old notifications failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Send emergency alert to all relevant users
     * 
     * @param array $data
     * @return array
     */
    public function sendEmergencyAlert(array $data)
    {
        try {
            // Emergency alerts go to admin and dispatcher roles
            $targetRoles = ['admin', 'dispatcher'];
            
            $notificationData = [
                'type' => 'emergency_alert',
                'title' => $data['title'] ?? 'Emergency Alert',
                'message' => $data['message'],
                'priority' => 'critical',
                'channels' => ['in_app', 'email', 'sms'],
                'recipient_roles' => $targetRoles,
                'additional_data' => $data['additional_data'] ?? []
            ];

            $notification = $this->sendNotification($notificationData);

            return [
                'success' => true,
                'notification_id' => $notification->id,
                'recipients_count' => User::role($targetRoles)->count()
            ];

        } catch (Exception $e) {
            Log::error("Emergency alert failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Log notification activity
     * 
     * @param int $notificationId
     * @return NotificationLog
     */
    public function logNotification(int $notificationId)
    {
        try {
            $notification = Notification::findOrFail($notificationId);
            
            return NotificationLog::create([
                'notification_id' => $notification->id,
                'notification_channel_id' => 1, // Default in-app channel
                'recipient_id' => $notification->user_id,
                'status' => 'logged',
                'sent_at' => now()
            ]);

        } catch (Exception $e) {
            Log::error("Notification logging failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get notification statistics
     * 
     * @param array $filters
     * @return array
     */
    public function getNotificationStatistics(array $filters = [])
    {
        $query = Notification::query();

        if (isset($filters['start_date'])) {
            $query->where('sent_at', '>=', $filters['start_date']);
        }

        if (isset($filters['end_date'])) {
            $query->where('sent_at', '<=', $filters['end_date']);
        }

        $notifications = $query->get();

        return [
            'total_sent' => $notifications->count(),
            'total_read' => $notifications->whereNotNull('read_at')->count(),
            'total_unread' => $notifications->whereNull('read_at')->count(),
            'by_type' => $notifications->groupBy('type')->map->count(),
            'by_priority' => $notifications->groupBy('priority')->map->count(),
            'read_rate' => $notifications->count() > 0 
                ? round(($notifications->whereNotNull('read_at')->count() / $notifications->count()) * 100, 2) 
                : 0
        ];
    }

    /**
     * Validate notification data
     * 
     * @param array $data
     * @throws Exception
     */
    private function validateNotificationData(array $data)
    {
        if (empty($data['type'])) {
            throw new Exception("Notification type is required");
        }

        if (empty($data['title'])) {
            throw new Exception("Notification title is required");
        }

        if (empty($data['message'])) {
            throw new Exception("Notification message is required");
        }
    }

    /**
     * Simulate email sending
     * 
     * @param Notification $notification
     * @return bool
     */
    private function sendEmail(Notification $notification)
    {
        // In production, integrate with actual email service (Mailgun, SendGrid, etc.)
        Log::info("Email sent (simulated)", [
            'notification_id' => $notification->id,
            'title' => $notification->title
        ]);
        return true;
    }

    /**
     * Simulate SMS sending
     * 
     * @param Notification $notification
     * @return bool
     */
    private function sendSMS(Notification $notification)
    {
        // In production, integrate with SMS service (Twilio, Nexmo, etc.)
        Log::info("SMS sent (simulated)", [
            'notification_id' => $notification->id,
            'title' => $notification->title
        ]);
        return true;
    }

    /**
     * Simulate push notification sending
     * 
     * @param Notification $notification
     * @return bool
     */
    private function sendPushNotification(Notification $notification)
    {
        // In production, integrate with push service (Firebase, OneSignal, etc.)
        Log::info("Push notification sent (simulated)", [
            'notification_id' => $notification->id,
            'title' => $notification->title
        ]);
        return true;
    }
}