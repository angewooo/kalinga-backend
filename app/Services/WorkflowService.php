<?php

namespace App\Services;

use App\Models\RequestEntry;
use App\Models\ResourceAllocation;
use App\Models\Assignment;
use App\Models\Responder;
use App\Models\Vehicle;
use App\Models\TransportRoute;
use App\Models\DeliveryPerformance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class WorkflowService
{
    protected $allocationService;
    protected $notificationService;

    public function __construct(AllocationService $allocationService, NotificationService $notificationService)
    {
        $this->allocationService = $allocationService;
        $this->notificationService = $notificationService;
    }

    /**
     * Process complete emergency request workflow
     * Request → Validation → Allocation → Assignment → Notification
     * 
     * @param array $requestData
     * @return array
     */
    public function processEmergencyRequest(array $requestData)
    {
        DB::beginTransaction();
        
        try {
            // Step 1: Create request entry
            $request = RequestEntry::create([
                'requesting_hospital_id' => $requestData['hospital_id'],
                'resource_type' => $requestData['resource_type'],
                'quantity_needed' => $requestData['quantity'],
                'urgency_level' => $requestData['urgency'] ?? 'normal',
                'request_reason' => $requestData['reason'] ?? null,
                'estimated_arrival' => $requestData['estimated_arrival'] ?? now()->addHours(2),
                'status' => 'pending',
                'requested_by' => auth()->id() ?? 1,
                'requested_at' => now()
            ]);

            Log::info("Emergency request created", ['request_id' => $request->id]);

            // Step 2: Check availability
            $availability = $this->allocationService->checkAvailability(
                $request->resource_type,
                $request->quantity_needed
            );

            if (!$availability['is_sufficient']) {
                $request->update(['status' => 'insufficient_resources']);
                
                // Send notification about insufficient resources
                $this->notificationService->sendNotification([
                    'type' => 'resource_shortage',
                    'title' => 'Insufficient Resources',
                    'message' => "Request #{$request->id} cannot be fulfilled due to insufficient {$request->resource_type}",
                    'priority' => 'high',
                    'channels' => ['in_app', 'email'],
                    'recipient_roles' => ['admin', 'dispatcher'],
                    'additional_data' => [
                        'request_id' => $request->id,
                        'resource_type' => $request->resource_type,
                        'quantity_needed' => $request->quantity_needed,
                        'available' => $availability['total_available']
                    ]
                ]);

                DB::commit();
                return [
                    'success' => false,
                    'request' => $request,
                    'message' => 'Insufficient resources available',
                    'availability' => $availability
                ];
            }

            // Step 3: Allocate resources
            $allocation = $this->allocationService->allocateResources($request);

            Log::info("Resources allocated", [
                'request_id' => $request->id,
                'allocation_id' => $allocation->id
            ]);

            // Step 4: Auto-assign responder if available
            $assignment = $this->autoAssignResponder($allocation);

            // Step 5: Send success notifications
            $this->notificationService->sendNotification([
                'type' => 'request_approved',
                'title' => 'Emergency Request Approved',
                'message' => "Request #{$request->id} has been approved and allocated from hospital #{$allocation->allocated_hospital_id}",
                'priority' => $request->urgency_level === 'critical' ? 'critical' : 'high',
                'channels' => ['in_app', 'email'],
                'user_id' => $request->requested_by,
                'additional_data' => [
                    'request_id' => $request->id,
                    'allocation_id' => $allocation->id,
                    'assignment_id' => $assignment?->id
                ]
            ]);

            DB::commit();

            return [
                'success' => true,
                'request' => $request->fresh(),
                'allocation' => $allocation,
                'assignment' => $assignment,
                'message' => 'Request processed successfully'
            ];

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Emergency request processing failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Assign responder to allocation
     * 
     * @param ResourceAllocation $allocation
     * @param int|null $responderId
     * @param int|null $vehicleId
     * @return Assignment
     */
    public function assignResponder(ResourceAllocation $allocation, ?int $responderId = null, ?int $vehicleId = null)
    {
        DB::beginTransaction();
        
        try {
            // If no responder specified, find available one
            if (!$responderId) {
                $responder = $this->findAvailableResponder($allocation);
                if (!$responder) {
                    throw new Exception("No available responders found");
                }
                $responderId = $responder->id;
            }

            // If no vehicle specified, find available one
            if (!$vehicleId) {
                $vehicle = $this->findAvailableVehicle();
                if (!$vehicle) {
                    throw new Exception("No available vehicles found");
                }
                $vehicleId = $vehicle->id;
            }

            // Create assignment
            $assignment = Assignment::create([
                'resource_allocation_id' => $allocation->id,
                'responder_id' => $responderId,
                'vehicle_id' => $vehicleId,
                'assignment_status' => 'assigned',
                'assigned_at' => now(),
                'estimated_delivery_time' => now()->addHours(2),
                'notes' => 'Auto-assigned by system'
            ]);

            // Update allocation status
            $allocation->update(['allocation_status' => 'assigned']);

            // Update responder status
            Responder::where('id', $responderId)->update(['status' => 'on_duty']);

            // Update vehicle status
            Vehicle::where('id', $vehicleId)->update(['status' => 'in_use']);

            // Create transport route
            $this->createTransportRoute($assignment, $allocation);

            // Send notification to responder
            $responder = Responder::find($responderId);
            $this->notificationService->sendNotification([
                'type' => 'assignment_created',
                'title' => 'New Assignment',
                'message' => "You have been assigned to deliver {$allocation->resource_type} to request #{$allocation->request_entry_id}",
                'priority' => 'high',
                'channels' => ['in_app', 'sms'],
                'user_id' => $responder->user_id,
                'additional_data' => [
                    'assignment_id' => $assignment->id,
                    'allocation_id' => $allocation->id,
                    'vehicle_id' => $vehicleId
                ]
            ]);

            DB::commit();

            Log::info("Responder assigned", [
                'assignment_id' => $assignment->id,
                'responder_id' => $responderId,
                'vehicle_id' => $vehicleId
            ]);

            return $assignment;

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Responder assignment failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Complete delivery and update all related records
     * 
     * @param int $assignmentId
     * @param array $deliveryData
     * @return DeliveryPerformance
     */
    public function completeDelivery(int $assignmentId, array $deliveryData = [])
    {
        DB::beginTransaction();
        
        try {
            $assignment = Assignment::with('resourceAllocation.requestEntry')->findOrFail($assignmentId);

            // Update assignment status
            $assignment->update([
                'assignment_status' => 'completed',
                'completed_at' => now(),
                'notes' => ($assignment->notes ?? '') . ' | ' . ($deliveryData['notes'] ?? 'Delivery completed successfully')
            ]);

            // Update allocation status
            $assignment->resourceAllocation->update([
                'allocation_status' => 'delivered'
            ]);

            // Update request status
            $assignment->resourceAllocation->requestEntry->update([
                'status' => 'completed'
            ]);

            // Update responder status
            Responder::where('id', $assignment->responder_id)->update(['status' => 'available']);

            // Update vehicle status
            Vehicle::where('id', $assignment->vehicle_id)->update(['status' => 'available']);

            // Calculate delivery performance metrics
            $performance = $this->calculateDeliveryPerformance($assignment, $deliveryData);

            // Send completion notifications
            $this->notificationService->sendNotification([
                'type' => 'delivery_completed',
                'title' => 'Delivery Completed',
                'message' => "Assignment #{$assignment->id} has been successfully completed",
                'priority' => 'normal',
                'channels' => ['in_app'],
                'user_id' => $assignment->resourceAllocation->requestEntry->requested_by,
                'additional_data' => [
                    'assignment_id' => $assignment->id,
                    'delivery_time' => $performance->actual_delivery_time,
                    'on_time' => $performance->on_time_delivery
                ]
            ]);

            DB::commit();

            Log::info("Delivery completed", [
                'assignment_id' => $assignment->id,
                'performance_id' => $performance->id
            ]);

            return $performance;

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Complete delivery failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Auto-assign responder based on availability and location
     * 
     * @param ResourceAllocation $allocation
     * @return Assignment|null
     */
    private function autoAssignResponder(ResourceAllocation $allocation)
    {
        try {
            $responder = $this->findAvailableResponder($allocation);
            $vehicle = $this->findAvailableVehicle();

            if (!$responder || !$vehicle) {
                Log::warning("Auto-assignment skipped - no available responder or vehicle");
                return null;
            }

            return $this->assignResponder($allocation, $responder->id, $vehicle->id);

        } catch (Exception $e) {
            Log::error("Auto-assign responder failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Find available responder
     * 
     * @param ResourceAllocation $allocation
     * @return Responder|null
     */
    private function findAvailableResponder(ResourceAllocation $allocation)
    {
        return Responder::where('status', 'available')
            ->where('is_active', true)
            ->with('responderDetail')
            ->orderBy('created_at', 'asc')
            ->first();
    }

    /**
     * Find available vehicle
     * 
     * @return Vehicle|null
     */
    private function findAvailableVehicle()
    {
        return Vehicle::where('status', 'available')
            ->where('is_active', true)
            ->orderBy('created_at', 'asc')
            ->first();
    }

    /**
     * Create transport route for assignment
     * 
     * @param Assignment $assignment
     * @param ResourceAllocation $allocation
     * @return TransportRoute
     */
    private function createTransportRoute(Assignment $assignment, ResourceAllocation $allocation)
    {
        try {
            $sourceHospital = $allocation->allocatedHospital;
            $destinationHospital = $allocation->requestEntry->requestingHospital;

            // Calculate estimated distance (simplified)
            $distance = $this->calculateDistance(
                $sourceHospital->latitude,
                $sourceHospital->longitude,
                $destinationHospital->latitude,
                $destinationHospital->longitude
            );

            // Estimate travel time (assuming average speed of 40 km/h)
            $estimatedTravelTime = ($distance / 40) * 60; // in minutes

            $route = TransportRoute::create([
                'assignment_id' => $assignment->id,
                'vehicle_id' => $assignment->vehicle_id,
                'start_location' => json_encode([
                    'hospital_id' => $sourceHospital->id,
                    'name' => $sourceHospital->name,
                    'latitude' => $sourceHospital->latitude,
                    'longitude' => $sourceHospital->longitude
                ]),
                'end_location' => json_encode([
                    'hospital_id' => $destinationHospital->id,
                    'name' => $destinationHospital->name,
                    'latitude' => $destinationHospital->latitude,
                    'longitude' => $destinationHospital->longitude
                ]),
                'waypoints' => json_encode([]),
                'distance_km' => round($distance, 2),
                'estimated_duration_minutes' => round($estimatedTravelTime, 0),
                'actual_duration_minutes' => null,
                'route_status' => 'planned',
                'started_at' => null,
                'completed_at' => null
            ]);

            Log::info("Transport route created", [
                'route_id' => $route->id,
                'assignment_id' => $assignment->id,
                'distance_km' => $route->distance_km
            ]);

            return $route;

        } catch (Exception $e) {
            Log::error("Transport route creation failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Calculate delivery performance metrics
     * 
     * @param Assignment $assignment
     * @param array $deliveryData
     * @return DeliveryPerformance
     */
    private function calculateDeliveryPerformance(Assignment $assignment, array $deliveryData)
    {
        try {
            $route = TransportRoute::where('assignment_id', $assignment->id)->first();

            // Calculate actual delivery time
            $actualDeliveryTime = $assignment->assigned_at->diffInMinutes($assignment->completed_at);

            // Check if on-time
            $onTime = $assignment->completed_at <= $assignment->estimated_delivery_time;

            // Calculate delay if any
            $delayMinutes = $onTime ? 0 : $assignment->estimated_delivery_time->diffInMinutes($assignment->completed_at);

            $performance = DeliveryPerformance::create([
                'assignment_id' => $assignment->id,
                'responder_id' => $assignment->responder_id,
                'vehicle_id' => $assignment->vehicle_id,
                'actual_delivery_time' => now(),
                'estimated_delivery_time' => $assignment->estimated_delivery_time,
                'delay_minutes' => $delayMinutes,
                'on_time_delivery' => $onTime,
                'distance_traveled_km' => $route?->distance_km ?? 0,
                'fuel_consumed_liters' => $deliveryData['fuel_consumed'] ?? null,
                'delivery_rating' => $deliveryData['rating'] ?? null,
                'notes' => $deliveryData['performance_notes'] ?? null
            ]);

            Log::info("Delivery performance recorded", [
                'performance_id' => $performance->id,
                'on_time' => $onTime,
                'delay_minutes' => $delayMinutes
            ]);

            return $performance;

        } catch (Exception $e) {
            Log::error("Delivery performance calculation failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Calculate distance between two coordinates (Haversine formula)
     * 
     * @param float $lat1
     * @param float $lon1
     * @param float $lat2
     * @param float $lon2
     * @return float Distance in kilometers
     */
    private function calculateDistance($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371; // Earth's radius in kilometers

        $latDiff = deg2rad($lat2 - $lat1);
        $lonDiff = deg2rad($lon2 - $lon1);

        $a = sin($latDiff / 2) * sin($latDiff / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($lonDiff / 2) * sin($lonDiff / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Update transport route status
     * 
     * @param int $assignmentId
     * @param string $status
     * @param array $data
     * @return TransportRoute
     */
    public function updateRouteStatus(int $assignmentId, string $status, array $data = [])
    {
        try {
            $route = TransportRoute::where('assignment_id', $assignmentId)->firstOrFail();

            $updateData = ['route_status' => $status];

            if ($status === 'in_progress' && !$route->started_at) {
                $updateData['started_at'] = now();
            }

            if ($status === 'completed') {
                $updateData['completed_at'] = now();
                if ($route->started_at) {
                    $updateData['actual_duration_minutes'] = $route->started_at->diffInMinutes(now());
                }
            }

            if (isset($data['current_location'])) {
                $updateData['waypoints'] = json_encode(array_merge(
                    json_decode($route->waypoints, true) ?? [],
                    [$data['current_location']]
                ));
            }

            $route->update($updateData);

            Log::info("Route status updated", [
                'route_id' => $route->id,
                'status' => $status
            ]);

            return $route;

        } catch (Exception $e) {
            Log::error("Route status update failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get workflow statistics
     * 
     * @param array $filters
     * @return array
     */
    public function getWorkflowStatistics(array $filters = [])
    {
        $query = RequestEntry::query();

        if (isset($filters['start_date'])) {
            $query->where('requested_at', '>=', $filters['start_date']);
        }

        if (isset($filters['end_date'])) {
            $query->where('requested_at', '<=', $filters['end_date']);
        }

        $requests = $query->with(['resourceAllocations.assignment'])->get();

        $completedAssignments = Assignment::where('assignment_status', 'completed')
            ->with('deliveryPerformance')
            ->get();

        return [
            'total_requests' => $requests->count(),
            'by_status' => $requests->groupBy('status')->map->count(),
            'by_urgency' => $requests->groupBy('urgency_level')->map->count(),
            'total_allocations' => ResourceAllocation::count(),
            'total_assignments' => Assignment::count(),
            'completed_deliveries' => $completedAssignments->count(),
            'on_time_deliveries' => $completedAssignments->filter(function($assignment) {
                return $assignment->deliveryPerformance?->on_time_delivery ?? false;
            })->count(),
            'average_completion_time' => $completedAssignments->avg(function($assignment) {
                return $assignment->assigned_at && $assignment->completed_at
                    ? $assignment->assigned_at->diffInMinutes($assignment->completed_at)
                    : 0;
            })
        ];
    }

    /**
     * Cancel request and rollback allocations
     * 
     * @param int $requestId
     * @param string $reason
     * @return bool
     */
    public function cancelRequest(int $requestId, string $reason)
    {
        DB::beginTransaction();
        
        try {
            $request = RequestEntry::with('resourceAllocations.assignment')->findOrFail($requestId);

            // Update request status
            $request->update([
                'status' => 'cancelled',
                'request_reason' => ($request->request_reason ?? '') . " | Cancelled: $reason"
            ]);

            // Handle allocations and assignments
            foreach ($request->resourceAllocations as $allocation) {
                $allocation->update(['allocation_status' => 'cancelled']);

                if ($assignment = $allocation->assignment) {
                    $assignment->update(['assignment_status' => 'cancelled']);
                    
                    // Release resources
                    if ($assignment->responder_id) {
                        Responder::where('id', $assignment->responder_id)->update(['status' => 'available']);
                    }
                    if ($assignment->vehicle_id) {
                        Vehicle::where('id', $assignment->vehicle_id)->update(['status' => 'available']);
                    }
                }
            }

            // Send cancellation notification
            $this->notificationService->sendNotification([
                'type' => 'request_cancelled',
                'title' => 'Request Cancelled',
                'message' => "Request #{$request->id} has been cancelled. Reason: $reason",
                'priority' => 'high',
                'channels' => ['in_app', 'email'],
                'user_id' => $request->requested_by,
                'additional_data' => [
                    'request_id' => $request->id,
                    'reason' => $reason
                ]
            ]);

            DB::commit();

            Log::info("Request cancelled", [
                'request_id' => $request->id,
                'reason' => $reason
            ]);

            return true;

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Request cancellation failed: " . $e->getMessage());
            throw $e;
        }
    }
}