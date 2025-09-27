<?php

namespace App\Http\Controllers\API;

use App\Models\Notification;
use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Notification Controller for Project Kalinga
 * Handles real-time notifications, channels, and delivery tracking
 */
class NotificationController extends BaseApiController
{
    /**
     * Get the model class for this controller
     */
    protected function getModel(): string
    {
        return Notification::class;
    }

    /**
     * Get validation rules for notification operations
     */
    protected function getValidationRules(): array
    {
        return [
            'user_id' => 'sometimes|integer|exists:users,id',
            'title' => 'required|string|max:100',
            'message' => 'required|string|max:1000',
            'type' => 'required|string|in:info,warning,error,success,emergency,system,resource_alert',
            'priority_level' => 'sometimes|integer|min:1|max:5',
        ];
    }

    /**
     * Get searchable fields
     */
    protected function getSearchableFields(): array
    {
        return ['title', 'message', 'type'];
    }

    /**
     * Get default relationships to load
     */
    protected function getDefaultRelations(): array
    {
        return ['user', 'notificationLogs'];
    }

    /**
     * Display user notifications
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            $request->validate($this->commonRules + [
                'status' => 'string|in:read,unread',
                'type' => 'string|in:info,warning,error,success,emergency,system,resource_alert',
                'priority_min' => 'integer|min:1|max:5',
                'date_from' => 'date',
                'date_to' => 'date|after_or_equal:date_from'
            ]);

            $query = Notification::where('user_id', $user->id)
                ->orWhereNull('user_id') // Include broadcast notifications
                ->with($this->getDefaultRelations());

            // Apply filters
            if ($status = $request->get('status')) {
                if ($status === 'read') {
                    $query->whereNotNull('read_at');
                } else {
                    $query->whereNull('read_at');
                }
            }

            if ($type = $request->get('type')) {
                $query->where('type', $type);
            }

            if ($priorityMin = $request->get('priority_min')) {
                $query->where('priority_level', '>=', $priorityMin);
            }

            if ($dateFrom = $request->get('date_from')) {
                $query->whereDate('created_at', '>=', $dateFrom);
            }

            if ($dateTo = $request->get('date_to')) {
                $query->whereDate('created_at', '<=', $dateTo);
            }

            $params = $this->getPaginationParams($request);
            $notifications = $query->orderBy('priority_level', 'desc')
                ->orderBy('created_at', 'desc')
                ->paginate($params['per_page']);

            // Add delivery status
            $notifications->getCollection()->transform(function ($notification) {
                $notification->is_read = !is_null($notification->read_at);
                $notification->delivery_status = $this->getDeliveryStatus($notification);
                $notification->time_ago = $notification->created_at->diffForHumans();
                return $notification;
            });

            return $this->paginatedResponse($notifications, 'Notifications retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieving notifications');
        }
    }

    /**
     * Get unread notifications
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function unread(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $unreadNotifications = Notification::where('user_id', $user->id)
                ->orWhereNull('user_id')
                ->whereNull('read_at')
                ->with($this->getDefaultRelations())
                ->orderBy('priority_level', 'desc')
                ->orderBy('created_at', 'desc')
                ->get();

            $summary = [
                'total_unread' => $unreadNotifications->count(),
                'by_type' => $unreadNotifications->groupBy('type')->map->count(),
                'by_priority' => $unreadNotifications->groupBy('priority_level')->map->count(),
                'critical_count' => $unreadNotifications->where('priority_level', 5)->count(),
                'notifications' => $unreadNotifications
            ];

            return $this->successResponse($summary, 'Unread notifications retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieving unread notifications');
        }
    }

    /**
     * Mark notification as read
     *
     * @param int $id
     * @return JsonResponse
     */
    public function markAsRead(int $id): JsonResponse
    {
        try {
            $user = auth('sanctum')->user();
            
            $notification = Notification::where('notif_id', $id)
                ->where(function($q) use ($user) {
                    $q->where('user_id', $user->id)
                      ->orWhereNull('user_id');
                })
                ->firstOrFail();

            if (!$notification->read_at) {
                $notification->update(['read_at' => now()]);

                $this->logActivity('Notification marked as read', [
                    'notification_id' => $notification->notif_id,
                    'user_id' => $user->id
                ]);
            }

            return $this->successResponse($notification, 'Notification marked as read.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'marking notification as read');
        }
    }

    /**
     * Mark all notifications as read
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $updated = Notification::where('user_id', $user->id)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);

            $this->logActivity('All notifications marked as read', [
                'user_id' => $user->id,
                'notifications_updated' => $updated
            ]);

            return $this->successResponse([
                'notifications_marked' => $updated
            ], 'All notifications marked as read.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'marking all notifications as read');
        }
    }

    /**
     * Delete notification
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $user = auth('sanctum')->user();
            
            $notification = Notification::where('notif_id', $id)
                ->where('user_id', $user->id)
                ->firstOrFail();

            $notification->delete();

            $this->logActivity('Notification deleted', [
                'notification_id' => $id,
                'user_id' => $user->id
            ]);

            return $this->deletedResponse('Notification deleted successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'deleting notification');
        }
    }

    /**
     * Get notification channels
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function channels(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $channels = NotificationChannel::where('user_id', $user->id)
                ->orderBy('channel_type')
                ->get();

            return $this->collectionResponse($channels, 'Notification channels retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieving notification channels');
        }
    }

    /**
     * Add notification channel
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function addChannel(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $validated = $request->validate([
                'channel_type' => 'required|string|in:email,sms,push,webhook',
                'address' => 'required|string|max:255',
                'is_active' => 'sometimes|boolean'
            ]);

            // Validate address format based on channel type
            if (!$this->validateChannelAddress($validated['channel_type'], $validated['address'])) {
                return $this->errorResponse('Invalid address format for the specified channel type.', 400);
            }

            // Check for duplicates
            $existing = NotificationChannel::where('user_id', $user->id)
                ->where('channel_type', $validated['channel_type'])
                ->where('address', $validated['address'])
                ->first();

            if ($existing) {
                return $this->errorResponse('This notification channel already exists.', 409);
            }

            $channel = NotificationChannel::create([
                'user_id' => $user->id,
                'channel_type' => $validated['channel_type'],
                'address' => $validated['address'],
                'is_active' => $validated['is_active'] ?? true
            ]);

            $this->logActivity('Notification channel added', [
                'channel_id' => $channel->channel_id,
                'channel_type' => $channel->channel_type,
                'user_id' => $user->id
            ]);

            return $this->createdResponse($channel, 'Notification channel added successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'adding notification channel');
        }
    }

    /**
     * Update notification channel
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function updateChannel(Request $request, int $id): JsonResponse
    {
        try {
            $user = $request->user();

            $channel = NotificationChannel::where('channel_id', $id)
                ->where('user_id', $user->id)
                ->firstOrFail();

            $validated = $request->validate([
                'address' => 'sometimes|string|max:255',
                'is_active' => 'sometimes|boolean'
            ]);

            // Validate address if provided
            if (isset($validated['address']) && 
                !$this->validateChannelAddress($channel->channel_type, $validated['address'])) {
                return $this->errorResponse('Invalid address format for this channel type.', 400);
            }

            $channel->update($validated);

            $this->logActivity('Notification channel updated', [
                'channel_id' => $channel->channel_id,
                'updated_fields' => array_keys($validated)
            ]);

            return $this->updatedResponse($channel, 'Notification channel updated successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'updating notification channel');
        }
    }

    /**
     * Remove notification channel
     *
     * @param int $id
     * @return JsonResponse
     */
    public function removeChannel(int $id): JsonResponse
    {
        try {
            $user = auth('sanctum')->user();

            $channel = NotificationChannel::where('channel_id', $id)
                ->where('user_id', $user->id)
                ->firstOrFail();

            $channel->delete();

            $this->logActivity('Notification channel removed', [
                'channel_id' => $id,
                'channel_type' => $channel->channel_type
            ]);

            return $this->deletedResponse('Notification channel removed successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'removing notification channel');
        }
    }

    /**
     * Broadcast notification to multiple users
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function broadcast(Request $request): JsonResponse
    {
        try {
            if (!$this->userCan('broadcast-notifications')) {
                return $this->forbiddenResponse('You do not have permission to broadcast notifications.');
            }

            $validated = $request->validate($this->getValidationRules() + [
                'target_users' => 'sometimes|array',
                'target_users.*' => 'integer|exists:users,id',
                'target_roles' => 'sometimes|array',
                'target_roles.*' => 'string|exists:roles,name',
                'target_hospitals' => 'sometimes|array',
                'target_hospitals.*' => 'integer|exists:hospitals,hospital_id',
                'broadcast_all' => 'sometimes|boolean',
                'schedule_at' => 'sometimes|date|after:now'
            ]);

            DB::beginTransaction();

            // Get target users
            $targetUsers = $this->getTargetUsers($validated);

            if ($targetUsers->count() === 0) {
                return $this->errorResponse('No target users found for broadcast.', 400);
            }

            $notifications = [];
            foreach ($targetUsers as $user) {
                $notification = Notification::create([
                    'user_id' => $user->id,
                    'title' => $validated['title'],
                    'message' => $validated['message'],
                    'type' => $validated['type'],
                    'priority_level' => $validated['priority_level'] ?? 3
                ]);

                $notifications[] = $notification;

                // Send to user's channels
                $this->sendToUserChannels($notification, $user);
            }

            DB::commit();

            $this->logActivity('Notification broadcast', [
                'total_recipients' => count($notifications),
                'type' => $validated['type'],
                'priority' => $validated['priority_level'] ?? 3
            ]);

            return $this->createdResponse([
                'notifications_sent' => count($notifications),
                'recipients' => $targetUsers->count(),
                'broadcast_id' => $notifications[0]->notif_id ?? null
            ], 'Notification broadcast successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->handleException($e, 'broadcasting notification');
        }
    }

    /**
     * Get notification logs
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function logs(Request $request): JsonResponse
    {
        try {
            if (!$this->userCan('view-notification-logs')) {
                return $this->forbiddenResponse('You do not have permission to view notification logs.');
            }

            $request->validate([
                'notification_id' => 'sometimes|integer|exists:notifications,notif_id',
                'delivery_status' => 'sometimes|string|in:sent,delivered,failed,pending',
                'channel_type' => 'sometimes|string|in:email,sms,push,webhook',
                'date_from' => 'sometimes|date',
                'date_to' => 'sometimes|date|after_or_equal:date_from'
            ]);

            $query = NotificationLog::with(['notification', 'notificationChannel']);

            if ($notificationId = $request->get('notification_id')) {
                $query->where('notif_id', $notificationId);
            }

            if ($deliveryStatus = $request->get('delivery_status')) {
                $query->where('delivery_status', $deliveryStatus);
            }

            if ($channelType = $request->get('channel_type')) {
                $query->whereHas('notificationChannel', function($q) use ($channelType) {
                    $q->where('channel_type', $channelType);
                });
            }

            if ($dateFrom = $request->get('date_from')) {
                $query->whereDate('sent_at', '>=', $dateFrom);
            }

            if ($dateTo = $request->get('date_to')) {
                $query->whereDate('sent_at', '<=', $dateTo);
            }

            $logs = $query->orderBy('sent_at', 'desc')->paginate(50);

            return $this->paginatedResponse($logs, 'Notification logs retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieving notification logs');
        }
    }

    /**
     * Get notification statistics
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            if (!$this->userCan('view-notification-stats')) {
                return $this->forbiddenResponse('You do not have permission to view notification statistics.');
            }

            $period = $request->get('period', '30days');
            $dateFrom = $this->parsePeriod($period);

            $stats = [
                'total_notifications' => Notification::where('created_at', '>=', $dateFrom)->count(),
                'by_type' => Notification::where('created_at', '>=', $dateFrom)
                    ->selectRaw('type, COUNT(*) as count')
                    ->groupBy('type')
                    ->pluck('count', 'type'),
                'by_priority' => Notification::where('created_at', '>=', $dateFrom)
                    ->selectRaw('priority_level, COUNT(*) as count')
                    ->groupBy('priority_level')
                    ->pluck('count', 'priority_level'),
                'read_rate' => $this->calculateReadRate($dateFrom),
                'delivery_stats' => $this->getDeliveryStats($dateFrom),
                'channel_performance' => $this->getChannelPerformance($dateFrom),
                'peak_hours' => $this->getPeakNotificationHours($dateFrom)
            ];

            return $this->successResponse($stats, 'Notification statistics retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieving notification statistics');
        }
    }

    /**
     * Helper Methods
     */

    /**
     * Get delivery status for a notification
     */
    private function getDeliveryStatus(Notification $notification): array
    {
        $logs = $notification->notificationLogs;
        
        return [
            'total_attempts' => $logs->count(),
            'successful_deliveries' => $logs->where('delivery_status', 'delivered')->count(),
            'failed_deliveries' => $logs->where('delivery_status', 'failed')->count(),
            'pending_deliveries' => $logs->where('delivery_status', 'pending')->count()
        ];
    }

    /**
     * Validate channel address format
     */
    private function validateChannelAddress(string $channelType, string $address): bool
    {
        return match($channelType) {
            'email' => filter_var($address, FILTER_VALIDATE_EMAIL) !== false,
            'sms' => preg_match('/^(\+63|0)?[0-9]{10}$/', $address),
            'push' => strlen($address) > 10, // Basic validation for push tokens
            'webhook' => filter_var($address, FILTER_VALIDATE_URL) !== false,
            default => false
        };
    }

    /**
     * Get target users for broadcast
     */
    private function getTargetUsers(array $params): \Illuminate\Database\Eloquent\Collection
    {
        if ($params['broadcast_all'] ?? false) {
            return User::all();
        }

        $users = collect();

        // Add specific users
        if (isset($params['target_users'])) {
            $users = $users->merge(User::whereIn('id', $params['target_users'])->get());
        }

        // Add users by roles
        if (isset($params['target_roles'])) {
            $roleUsers = User::whereHas('roles', function($q) use ($params) {
                $q->whereIn('name', $params['target_roles']);
            })->get();
            $users = $users->merge($roleUsers);
        }

        // Add users by hospitals
        if (isset($params['target_hospitals'])) {
            $hospitalUsers = User::whereHas('responder', function($q) use ($params) {
                $q->whereIn('hospital_id', $params['target_hospitals']);
            })->get();
            $users = $users->merge($hospitalUsers);
        }

        return $users->unique('id');
    }

    /**
     * Send notification to user's channels
     */
    private function sendToUserChannels(Notification $notification, User $user): void
    {
        $channels = $user->notificationChannels()->where('is_active', true)->get();

        foreach ($channels as $channel) {
            NotificationLog::create([
                'notif_id' => $notification->notif_id,
                'channel_id' => $channel->channel_id,
                'delivery_status' => 'sent',
                'sent_at' => now()
            ]);

            // In production, this would trigger actual delivery
            // via email service, SMS gateway, push notification service, etc.
        }
    }

    /**
     * Parse period string to date
     */
    private function parsePeriod(string $period): \Carbon\Carbon
    {
        return match($period) {
            '7days' => now()->subDays(7),
            '30days' => now()->subDays(30),
            '90days' => now()->subDays(90),
            default => now()->subDays(30)
        };
    }

    /**
     * Calculate read rate
     */
    private function calculateReadRate(\Carbon\Carbon $dateFrom): float
    {
        $total = Notification::where('created_at', '>=', $dateFrom)->count();
        $read = Notification::where('created_at', '>=', $dateFrom)
            ->whereNotNull('read_at')->count();

        return $total > 0 ? round(($read / $total) * 100, 2) : 0;
    }

    /**
     * Get delivery statistics
     */
    private function getDeliveryStats(\Carbon\Carbon $dateFrom): array
    {
        $logs = NotificationLog::where('sent_at', '>=', $dateFrom)->get();

        return [
            'total_attempts' => $logs->count(),
            'successful' => $logs->where('delivery_status', 'delivered')->count(),
            'failed' => $logs->where('delivery_status', 'failed')->count(),
            'pending' => $logs->where('delivery_status', 'pending')->count(),
            'success_rate' => $logs->count() > 0 
                ? round(($logs->where('delivery_status', 'delivered')->count() / $logs->count()) * 100, 2)
                : 0
        ];
    }

    /**
     * Get channel performance metrics
     */
    private function getChannelPerformance(\Carbon\Carbon $dateFrom): array
    {
        return NotificationLog::where('sent_at', '>=', $dateFrom)
            ->join('notification_channels', 'notification_logs.channel_id', '=', 'notification_channels.channel_id')
            ->selectRaw('notification_channels.channel_type, 
                COUNT(*) as total_sent,
                SUM(CASE WHEN delivery_status = "delivered" THEN 1 ELSE 0 END) as delivered,
                AVG(CASE WHEN delivery_status = "delivered" THEN 1 ELSE 0 END) * 100 as success_rate')
            ->groupBy('notification_channels.channel_type')
            ->get()
            ->mapWithKeys(function($item) {
                return [$item->channel_type => [
                    'total_sent' => $item->total_sent,
                    'delivered' => $item->delivered,
                    'success_rate' => round($item->success_rate, 2)
                ]];
            })
            ->toArray();
    }

    /**
     * Get peak notification hours
     */
    private function getPeakNotificationHours(\Carbon\Carbon $dateFrom): array
    {
        return Notification::where('created_at', '>=', $dateFrom)
            ->selectRaw('HOUR(created_at) as hour, COUNT(*) as count')
            ->groupBy('hour')
            ->orderBy('count', 'desc')
            ->limit(5)
            ->pluck('count', 'hour')
            ->toArray();
    }
}