<?php

namespace App\Listeners;

use App\Events\RequestEntryCreated;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendRequestNotification implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(RequestEntryCreated $event): void
    {
        $request = $event->requestEntry;

        // Determine notification priority based on urgency
        $priority = match($request->urgency_level) {
            'critical' => 'critical',
            'high' => 'high',
            'medium' => 'medium',
            default => 'low'
        };

        // Get users to notify (dispatchers, admins, hospital staff)
        $usersToNotify = User::whereIn('role', ['dispatcher', 'admin', 'medical_staff'])
            ->where('is_active', true)
            ->get();

        foreach ($usersToNotify as $user) {
            try {
                Notification::create([
                    'user_id' => $user->id,
                    'type' => $request->urgency_level === 'critical' ? 'alert' : 'info',
                    'title' => "New {$request->urgency_level} request",
                    'message' => "Emergency request for {$request->resource_type} - Quantity: {$request->quantity_needed}",
                    'priority' => $priority,
                    'action_url' => "/requests/{$request->id}",
                    'is_read' => false,
                    'metadata' => json_encode([
                        'request_id' => $request->id,
                        'hospital_id' => $request->requesting_hospital_id,
                        'resource_type' => $request->resource_type,
                        'quantity' => $request->quantity_needed,
                    ]),
                ]);

                Log::info("Notification sent to user {$user->id} for request {$request->id}");
            } catch (\Exception $e) {
                Log::error("Failed to send notification: " . $e->getMessage());
            }
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(RequestEntryCreated $event, \Throwable $exception): void
    {
        Log::error("SendRequestNotification failed: " . $exception->getMessage());
    }
}