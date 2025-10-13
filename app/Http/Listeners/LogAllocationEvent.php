<?php

namespace App\Listeners;

use App\Events\AllocationCompleted;
use App\Models\InventoryLog;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class LogAllocationEvent implements ShouldQueue
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
    public function handle(AllocationCompleted $event): void
    {
        $allocation = $event->allocation;

        // Log the allocation in inventory logs
        try {
            InventoryLog::create([
                'hospital_id' => $allocation->allocated_hospital_id,
                'resource_type' => $allocation->requestEntry->resource_type ?? 'unknown',
                'transaction_type' => 'allocation',
                'quantity_change' => -$allocation->allocated_quantity, // Negative for outgoing
                'previous_quantity' => $allocation->hospitalResource->quantity_available ?? 0,
                'new_quantity' => ($allocation->hospitalResource->quantity_available ?? 0) - $allocation->allocated_quantity,
                'reference_id' => $allocation->id,
                'reference_type' => 'allocation',
                'performed_by' => null, // System-generated
                'notes' => "Resource allocated for request #{$allocation->request_id}",
            ]);

            Log::info("Inventory log created for allocation {$allocation->id}");
        } catch (\Exception $e) {
            Log::error("Failed to create inventory log: " . $e->getMessage());
        }

        // Notify relevant users about successful allocation
        try {
            $request = $allocation->requestEntry;
            
            // Get hospital staff and dispatchers
            $usersToNotify = User::whereIn('role', ['medical_staff', 'dispatcher'])
                ->where('is_active', true)
                ->get();

            foreach ($usersToNotify as $user) {
                Notification::create([
                    'user_id' => $user->id,
                    'type' => 'success',
                    'title' => 'Resource Allocation Completed',
                    'message' => "Allocation completed for request #{$request->id}. {$allocation->allocated_quantity} units allocated.",
                    'priority' => 'medium',
                    'action_url' => "/allocations/{$allocation->id}",
                    'is_read' => false,
                    'metadata' => json_encode([
                        'allocation_id' => $allocation->id,
                        'request_id' => $allocation->request_id,
                        'hospital_id' => $allocation->allocated_hospital_id,
                        'quantity' => $allocation->allocated_quantity,
                    ]),
                ]);
            }

            Log::info("Allocation notifications sent for allocation {$allocation->id}");
        } catch (\Exception $e) {
            Log::error("Failed to send allocation notifications: " . $e->getMessage());
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(AllocationCompleted $event, \Throwable $exception): void
    {
        Log::error("LogAllocationEvent failed: " . $exception->getMessage());
    }
}