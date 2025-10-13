<?php

namespace App\Listeners;

use App\Events\ResourceThresholdBreached;
use App\Models\Notification;
use App\Models\User;
use App\Models\AutomatedAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class TriggerResourceAlert implements ShouldQueue
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
    public function handle(ResourceThresholdBreached $event): void
    {
        $resource = $event->resource;
        $threshold = $event->threshold;
        $breachType = $event->breachType;

        // Determine notification priority
        $priority = $breachType === 'critical' ? 'critical' : 'high';
        $type = $breachType === 'critical' ? 'alert' : 'warning';

        // Get users to notify (admins, hospital staff, dispatchers)
        $usersToNotify = User::whereIn('role', ['admin', 'medical_staff', 'dispatcher'])
            ->where('is_active', true)
            ->get();

        foreach ($usersToNotify as $user) {
            try {
                Notification::create([
                    'user_id' => $user->id,
                    'type' => $type,
                    'title' => ucfirst($breachType) . " Resource Alert",
                    'message' => "Resource {$resource->resource_type} at hospital {$resource->hospital_id} has fallen below {$breachType} threshold. Current: {$resource->quantity_available}, Threshold: " . 
                        ($breachType === 'critical' ? $threshold->critical_level : $threshold->reorder_level),
                    'priority' => $priority,
                    'action_url' => "/resources/{$resource->id}",
                    'is_read' => false,
                    'metadata' => json_encode([
                        'resource_id' => $resource->id,
                        'hospital_id' => $resource->hospital_id,
                        'resource_type' => $resource->resource_type,
                        'current_quantity' => $resource->quantity_available,
                        'breach_type' => $breachType,
                    ]),
                ]);

                Log::info("Resource alert sent to user {$user->id} for resource {$resource->id}");
            } catch (\Exception $e) {
                Log::error("Failed to send resource alert: " . $e->getMessage());
            }
        }

        // Create automated action if critical
        if ($breachType === 'critical') {
            try {
                AutomatedAction::create([
                    'trigger_type' => 'threshold_breach',
                    'trigger_id' => $resource->id,
                    'action_type' => 'auto_reorder',
                    'action_status' => 'pending',
                    'action_data' => json_encode([
                        'resource_type' => $resource->resource_type,
                        'reorder_quantity' => $threshold->optimal_level - $resource->quantity_available,
                        'hospital_id' => $resource->hospital_id,
                    ]),
                    'executed_at' => null,
                ]);

                Log::info("Automated reorder action created for resource {$resource->id}");
            } catch (\Exception $e) {
                Log::error("Failed to create automated action: " . $e->getMessage());
            }
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(ResourceThresholdBreached $event, \Throwable $exception): void
    {
        Log::error("TriggerResourceAlert failed: " . $exception->getMessage());
    }
}