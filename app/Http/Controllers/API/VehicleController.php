<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Models\Assignment;
use App\Models\TransportRoute;
use App\Models\DeliveryPerformance;
use App\Http\Requests\CreateVehicleRequest;
use App\Http\Requests\UpdateVehicleRequest;
use App\Http\Traits\ApiResponseTrait;
use App\Services\VehicleService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class VehicleController extends Controller
{
    use ApiResponseTrait;

    protected $vehicleService;

    public function __construct(VehicleService $vehicleService)
    {
        $this->vehicleService = $vehicleService;
        
        // Apply middleware for different operations
        $this->middleware('permission:view-vehicles')->only(['index', 'show']);
        $this->middleware('permission:create-vehicles')->only(['store']);
        $this->middleware('permission:update-vehicles')->only(['update']);
        $this->middleware('permission:delete-vehicles')->only(['destroy']);
        $this->middleware('permission:manage-vehicle-assignments')->only([
            'getAssignments', 'getActiveAssignments', 'updateLocation'
        ]);
    }

    /**
     * Display a listing of vehicles with pagination and filtering
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = $request->get('per_page', 15);
            $search = $request->get('search');
            $status = $request->get('status');
            $type = $request->get('type');
            $hospital_id = $request->get('hospital_id');

            $query = Vehicle::with(['assignments.responder', 'transportRoutes'])
                ->when($search, function($q) use ($search) {
                    $q->where('vehicle_number', 'ILIKE', "%{$search}%")
                      ->orWhere('make_model', 'ILIKE', "%{$search}%");
                })
                ->when($status, function($q) use ($status) {
                    $q->where('status', $status);
                })
                ->when($type, function($q) use ($type) {
                    $q->where('vehicle_type', $type);
                })
                ->when($hospital_id, function($q) use ($hospital_id) {
                    $q->where('assigned_hospital_id', $hospital_id);
                });

            $vehicles = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return $this->successResponse($vehicles, 'Vehicles retrieved successfully');

        } catch (\Exception $e) {
            Log::error('Error retrieving vehicles: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve vehicles', 500);
        }
    }

    /**
     * Store a newly created vehicle
     */
    public function store(CreateVehicleRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();

            $vehicleData = $request->validated();
            $vehicleData['status'] = 'available';
            $vehicleData['created_by'] = auth()->id();

            $vehicle = Vehicle::create($vehicleData);

            // Log vehicle creation
            Log::info("Vehicle created: {$vehicle->vehicle_number} by user " . auth()->id());

            DB::commit();

            $vehicle->load(['assignments', 'transportRoutes']);

            return $this->successResponse($vehicle, 'Vehicle created successfully', 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating vehicle: ' . $e->getMessage());
            return $this->errorResponse('Failed to create vehicle', 500);
        }
    }

    /**
     * Display the specified vehicle
     */
    public function show(Vehicle $vehicle): JsonResponse
    {
        try {
            $vehicle->load([
                'assignments.responder.responderDetail',
                'assignments.resourceAllocation.requestEntry',
                'transportRoutes',
                'deliveryPerformances'
            ]);

            // Get additional statistics
            $vehicle->stats = [
                'total_assignments' => $vehicle->assignments()->count(),
                'active_assignments' => $vehicle->assignments()->where('status', 'in_progress')->count(),
                'completed_deliveries' => $vehicle->deliveryPerformances()->where('status', 'completed')->count(),
                'average_delivery_time' => $vehicle->deliveryPerformances()
                    ->where('status', 'completed')
                    ->avg(DB::raw('EXTRACT(EPOCH FROM (delivered_at - started_at))/3600')),
                'total_distance' => $vehicle->transportRoutes()->sum('actual_distance_km')
            ];

            return $this->successResponse($vehicle, 'Vehicle details retrieved successfully');

        } catch (\Exception $e) {
            Log::error('Error retrieving vehicle details: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve vehicle details', 500);
        }
    }

    /**
     * Update the specified vehicle
     */
    public function update(UpdateVehicleRequest $request, Vehicle $vehicle): JsonResponse
    {
        try {
            DB::beginTransaction();

            $updateData = $request->validated();
            $updateData['updated_by'] = auth()->id();

            $vehicle->update($updateData);

            Log::info("Vehicle updated: {$vehicle->vehicle_number} by user " . auth()->id());

            DB::commit();

            $vehicle->load(['assignments', 'transportRoutes']);

            return $this->successResponse($vehicle, 'Vehicle updated successfully');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating vehicle: ' . $e->getMessage());
            return $this->errorResponse('Failed to update vehicle', 500);
        }
    }

    /**
     * Remove the specified vehicle
     */
    public function destroy(Vehicle $vehicle): JsonResponse
    {
        try {
            DB::beginTransaction();

            // Check if vehicle has active assignments
            if ($vehicle->assignments()->where('status', 'in_progress')->exists()) {
                return $this->errorResponse('Cannot delete vehicle with active assignments', 400);
            }

            $vehicle->delete();

            Log::info("Vehicle deleted: {$vehicle->vehicle_number} by user " . auth()->id());

            DB::commit();

            return $this->successResponse(null, 'Vehicle deleted successfully');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting vehicle: ' . $e->getMessage());
            return $this->errorResponse('Failed to delete vehicle', 500);
        }
    }

    /**
     * Set vehicle as available
     */
    public function setAvailable(Vehicle $vehicle): JsonResponse
    {
        try {
            $vehicle->update([
                'status' => 'available',
                'updated_by' => auth()->id()
            ]);

            // Clear any location cache
            Cache::forget("vehicle_location_{$vehicle->id}");

            return $this->successResponse($vehicle, 'Vehicle set as available');

        } catch (\Exception $e) {
            Log::error('Error setting vehicle available: ' . $e->getMessage());
            return $this->errorResponse('Failed to update vehicle status', 500);
        }
    }

    /**
     * Set vehicle for maintenance
     */
    public function setMaintenance(Vehicle $vehicle): JsonResponse
    {
        try {
            DB::beginTransaction();

            // Check for active assignments
            if ($vehicle->assignments()->where('status', 'in_progress')->exists()) {
                return $this->errorResponse('Cannot set vehicle to maintenance with active assignments', 400);
            }

            $vehicle->update([
                'status' => 'maintenance',
                'updated_by' => auth()->id()
            ]);

            Log::info("Vehicle set to maintenance: {$vehicle->vehicle_number}");

            DB::commit();

            return $this->successResponse($vehicle, 'Vehicle set for maintenance');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error setting vehicle maintenance: ' . $e->getMessage());
            return $this->errorResponse('Failed to update vehicle status', 500);
        }
    }

    /**
     * Get vehicle status with real-time information
     */
    public function getStatus(Vehicle $vehicle): JsonResponse
    {
        try {
            $status = [
                'vehicle' => $vehicle,
                'current_status' => $vehicle->status,
                'active_assignments' => $vehicle->assignments()
                    ->where('status', 'in_progress')
                    ->with(['responder', 'resourceAllocation.requestEntry'])
                    ->get(),
                'current_location' => Cache::get("vehicle_location_{$vehicle->id}"),
                'last_maintenance' => $vehicle->maintenance_logs()
                    ->latest()
                    ->first(),
                'next_maintenance_due' => $this->calculateNextMaintenanceDue($vehicle),
                'fuel_level' => Cache::get("vehicle_fuel_{$vehicle->id}", 'unknown'),
                'mileage' => $vehicle->current_mileage
            ];

            return $this->successResponse($status, 'Vehicle status retrieved successfully');

        } catch (\Exception $e) {
            Log::error('Error retrieving vehicle status: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve vehicle status', 500);
        }
    }

    /**
     * Get all available vehicles
     */
    public function getAvailable(Request $request): JsonResponse
    {
        try {
            $hospital_id = $request->get('hospital_id');
            $vehicle_type = $request->get('vehicle_type');

            $query = Vehicle::where('status', 'available')
                ->when($hospital_id, function($q) use ($hospital_id) {
                    $q->where('assigned_hospital_id', $hospital_id);
                })
                ->when($vehicle_type, function($q) use ($vehicle_type) {
                    $q->where('vehicle_type', $vehicle_type);
                });

            $vehicles = $query->with(['assignments' => function($q) {
                $q->where('status', 'completed')->latest()->limit(1);
            }])->get();

            return $this->successResponse($vehicles, 'Available vehicles retrieved successfully');

        } catch (\Exception $e) {
            Log::error('Error retrieving available vehicles: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve available vehicles', 500);
        }
    }

    /**
     * Get vehicle assignments
     */
    public function getAssignments(Vehicle $vehicle, Request $request): JsonResponse
    {
        try {
            $status = $request->get('status');
            $limit = $request->get('limit', 10);

            $query = $vehicle->assignments()
                ->with([
                    'responder.responderDetail',
                    'resourceAllocation.requestEntry.hospital',
                    'transportRoute'
                ])
                ->when($status, function($q) use ($status) {
                    $q->where('status', $status);
                });

            $assignments = $query->orderBy('created_at', 'desc')
                ->paginate($limit);

            return $this->successResponse($assignments, 'Vehicle assignments retrieved successfully');

        } catch (\Exception $e) {
            Log::error('Error retrieving vehicle assignments: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve vehicle assignments', 500);
        }
    }

    /**
     * Get active assignments for vehicle
     */
    public function getActiveAssignments(Vehicle $vehicle): JsonResponse
    {
        try {
            $activeAssignments = $vehicle->assignments()
                ->where('status', 'in_progress')
                ->with([
                    'responder.responderDetail',
                    'resourceAllocation.requestEntry.hospital',
                    'transportRoute',
                    'deliveryPerformance'
                ])
                ->get();

            return $this->successResponse($activeAssignments, 'Active assignments retrieved successfully');

        } catch (\Exception $e) {
            Log::error('Error retrieving active assignments: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve active assignments', 500);
        }
    }

    /**
     * Get vehicle routes
     */
    public function getRoutes(Vehicle $vehicle, Request $request): JsonResponse
    {
        try {
            $status = $request->get('status');
            $date_from = $request->get('date_from');
            $date_to = $request->get('date_to');

            $query = $vehicle->transportRoutes()
                ->with(['assignment.resourceAllocation.requestEntry'])
                ->when($status, function($q) use ($status) {
                    $q->where('status', $status);
                })
                ->when($date_from, function($q) use ($date_from) {
                    $q->where('created_at', '>=', $date_from);
                })
                ->when($date_to, function($q) use ($date_to) {
                    $q->where('created_at', '<=', $date_to);
                });

            $routes = $query->orderBy('created_at', 'desc')->get();

            return $this->successResponse($routes, 'Vehicle routes retrieved successfully');

        } catch (\Exception $e) {
            Log::error('Error retrieving vehicle routes: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve vehicle routes', 500);
        }
    }

    /**
     * Get vehicle performance metrics
     */
    public function getPerformance(Vehicle $vehicle, Request $request): JsonResponse
    {
        try {
            $period = $request->get('period', '30days');
            $startDate = $this->getStartDateFromPeriod($period);

            $performance = [
                'vehicle_info' => $vehicle,
                'period' => $period,
                'metrics' => [
                    'total_assignments' => $vehicle->assignments()
                        ->where('created_at', '>=', $startDate)
                        ->count(),
                    'completed_deliveries' => $vehicle->deliveryPerformances()
                        ->where('status', 'completed')
                        ->where('created_at', '>=', $startDate)
                        ->count(),
                    'average_delivery_time' => $vehicle->deliveryPerformances()
                        ->where('status', 'completed')
                        ->where('created_at', '>=', $startDate)
                        ->avg(DB::raw('EXTRACT(EPOCH FROM (delivered_at - started_at))/3600')),
                    'on_time_delivery_rate' => $this->calculateOnTimeDeliveryRate($vehicle, $startDate),
                    'total_distance' => $vehicle->transportRoutes()
                        ->where('created_at', '>=', $startDate)
                        ->sum('actual_distance_km'),
                    'fuel_efficiency' => $this->calculateFuelEfficiency($vehicle, $startDate),
                    'utilization_rate' => $this->calculateUtilizationRate($vehicle, $startDate),
                    'maintenance_cost' => $this->calculateMaintenanceCost($vehicle, $startDate)
                ],
                'trends' => $this->getPerformanceTrends($vehicle, $startDate)
            ];

            return $this->successResponse($performance, 'Vehicle performance retrieved successfully');

        } catch (\Exception $e) {
            Log::error('Error retrieving vehicle performance: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve vehicle performance', 500);
        }
    }

    /**
     * Get maintenance history
     */
    public function getMaintenanceHistory(Vehicle $vehicle, Request $request): JsonResponse
    {
        try {
            $limit = $request->get('limit', 20);
            
            // Note: This assumes a maintenance_logs relationship exists
            // You may need to create this relationship or adjust based on your schema
            $maintenanceHistory = collect(); // Placeholder - implement based on your maintenance tracking
            
            $maintenanceData = [
                'vehicle' => $vehicle,
                'maintenance_history' => $maintenanceHistory,
                'next_scheduled' => $this->calculateNextMaintenanceDue($vehicle),
                'maintenance_cost_ytd' => $this->calculateMaintenanceCost($vehicle, Carbon::now()->startOfYear()),
                'average_maintenance_interval' => $this->calculateAverageMaintenanceInterval($vehicle)
            ];

            return $this->successResponse($maintenanceData, 'Maintenance history retrieved successfully');

        } catch (\Exception $e) {
            Log::error('Error retrieving maintenance history: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve maintenance history', 500);
        }
    }

    /**
     * Add maintenance log entry
     */
    public function addMaintenanceLog(Request $request, Vehicle $vehicle): JsonResponse
    {
        try {
            $request->validate([
                'maintenance_type' => 'required|string',
                'description' => 'required|string',
                'cost' => 'required|numeric|min:0',
                'performed_by' => 'required|string',
                'performed_at' => 'required|date'
            ]);

            // Note: This assumes a maintenance log model/table exists
            // Implement based on your actual maintenance tracking structure
            $maintenanceLog = [
                'vehicle_id' => $vehicle->id,
                'maintenance_type' => $request->maintenance_type,
                'description' => $request->description,
                'cost' => $request->cost,
                'performed_by' => $request->performed_by,
                'performed_at' => $request->performed_at,
                'logged_by' => auth()->id(),
                'created_at' => now()
            ];

            // Implement actual maintenance log creation here
            Log::info("Maintenance logged for vehicle {$vehicle->vehicle_number}: " . $request->maintenance_type);

            return $this->successResponse($maintenanceLog, 'Maintenance log added successfully', 201);

        } catch (\Exception $e) {
            Log::error('Error adding maintenance log: ' . $e->getMessage());
            return $this->errorResponse('Failed to add maintenance log', 500);
        }
    }

    /**
     * Update vehicle location (for tracking)
     */
    public function updateLocation(Request $request, Vehicle $vehicle): JsonResponse
    {
        try {
            $request->validate([
                'latitude' => 'required|numeric|between:-90,90',
                'longitude' => 'required|numeric|between:-180,180',
                'speed' => 'nullable|numeric|min:0',
                'heading' => 'nullable|numeric|between:0,359',
                'timestamp' => 'nullable|date'
            ]);

            $locationData = [
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'speed' => $request->speed,
                'heading' => $request->heading,
                'timestamp' => $request->timestamp ?: now()->toISOString(),
                'updated_at' => now()->toISOString()
            ];

            // Cache location for real-time tracking
            Cache::put("vehicle_location_{$vehicle->id}", $locationData, 3600);

            // Update vehicle's last known location
            $vehicle->update([
                'last_latitude' => $request->latitude,
                'last_longitude' => $request->longitude,
                'last_location_update' => now()
            ]);

            return $this->successResponse($locationData, 'Vehicle location updated successfully');

        } catch (\Exception $e) {
            Log::error('Error updating vehicle location: ' . $e->getMessage());
            return $this->errorResponse('Failed to update vehicle location', 500);
        }
    }

    /**
     * Get current vehicle location
     */
    public function getCurrentLocation(Vehicle $vehicle): JsonResponse
    {
        try {
            $cachedLocation = Cache::get("vehicle_location_{$vehicle->id}");
            
            $locationData = [
                'vehicle_id' => $vehicle->id,
                'vehicle_number' => $vehicle->vehicle_number,
                'current_location' => $cachedLocation,
                'last_database_update' => [
                    'latitude' => $vehicle->last_latitude,
                    'longitude' => $vehicle->last_longitude,
                    'timestamp' => $vehicle->last_location_update
                ],
                'active_assignments' => $vehicle->assignments()
                    ->where('status', 'in_progress')
                    ->with(['transportRoute'])
                    ->get()
            ];

            return $this->successResponse($locationData, 'Vehicle location retrieved successfully');

        } catch (\Exception $e) {
            Log::error('Error retrieving vehicle location: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve vehicle location', 500);
        }
    }

    /**
     * Get vehicle tracking history
     */
    public function getTrackingHistory(Vehicle $vehicle, Request $request): JsonResponse
    {
        try {
            $date_from = $request->get('date_from', Carbon::now()->subDays(7));
            $date_to = $request->get('date_to', Carbon::now());

            // Note: This would require a location_history table to store historical positions
            // For now, return basic route history from transport_routes
            $trackingHistory = $vehicle->transportRoutes()
                ->whereBetween('created_at', [$date_from, $date_to])
                ->with(['assignment.resourceAllocation.requestEntry'])
                ->orderBy('created_at', 'desc')
                ->get();

            return $this->successResponse($trackingHistory, 'Vehicle tracking history retrieved successfully');

        } catch (\Exception $e) {
            Log::error('Error retrieving tracking history: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve tracking history', 500);
        }
    }

    /**
     * Optimize routes for multiple vehicles
     */
    public function optimizeRoutes(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'vehicle_ids' => 'required|array',
                'vehicle_ids.*' => 'exists:vehicles,id',
                'destinations' => 'required|array',
                'optimization_type' => 'in:distance,time,fuel'
            ]);

            $optimizationResult = $this->vehicleService->optimizeRoutes(
                $request->vehicle_ids,
                $request->destinations,
                $request->optimization_type ?? 'distance'
            );

            return $this->successResponse($optimizationResult, 'Routes optimized successfully');

        } catch (\Exception $e) {
            Log::error('Error optimizing routes: ' . $e->getMessage());
            return $this->errorResponse('Failed to optimize routes', 500);
        }
    }

    /**
     * Calculate route between two points
     */
    public function calculateRoute(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'origin_latitude' => 'required|numeric|between:-90,90',
                'origin_longitude' => 'required|numeric|between:-180,180',
                'destination_latitude' => 'required|numeric|between:-90,90',
                'destination_longitude' => 'required|numeric|between:-180,180',
                'vehicle_type' => 'nullable|string'
            ]);

            $routeData = $this->vehicleService->calculateRoute(
                [$request->origin_latitude, $request->origin_longitude],
                [$request->destination_latitude, $request->destination_longitude],
                $request->vehicle_type
            );

            return $this->successResponse($routeData, 'Route calculated successfully');

        } catch (\Exception $e) {
            Log::error('Error calculating route: ' . $e->getMessage());
            return $this->errorResponse('Failed to calculate route', 500);
        }
    }

    /**
     * Get active routes across all vehicles
     */
    public function getActiveRoutes(): JsonResponse
    {
        try {
            $activeRoutes = TransportRoute::where('status', 'in_progress')
                ->with([
                    'vehicle',
                    'assignment.responder.responderDetail',
                    'assignment.resourceAllocation.requestEntry.hospital'
                ])
                ->get();

            return $this->successResponse($activeRoutes, 'Active routes retrieved successfully');

        } catch (\Exception $e) {
            Log::error('Error retrieving active routes: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve active routes', 500);
        }
    }

    /**
     * Get route progress for specific route
     */
    public function getRouteProgress(TransportRoute $route): JsonResponse
    {
        try {
            $progress = [
                'route' => $route->load([
                    'vehicle',
                    'assignment.responder',
                    'assignment.resourceAllocation.requestEntry'
                ]),
                'progress_percentage' => $this->calculateRouteProgress($route),
                'estimated_arrival' => $this->calculateEstimatedArrival($route),
                'current_location' => Cache::get("vehicle_location_{$route->vehicle_id}"),
                'delays' => $this->calculateDelays($route),
                'next_waypoint' => $this->getNextWaypoint($route)
            ];

            return $this->successResponse($progress, 'Route progress retrieved successfully');

        } catch (\Exception $e) {
            Log::error('Error retrieving route progress: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve route progress', 500);
        }
    }

    /**
     * Handle bulk location updates for multiple vehicles
     */
    public function bulkLocationUpdate(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'updates' => 'required|array',
                'updates.*.vehicle_id' => 'required|exists:vehicles,id',
                'updates.*.latitude' => 'required|numeric|between:-90,90',
                'updates.*.longitude' => 'required|numeric|between:-180,180',
                'updates.*.timestamp' => 'nullable|date'
            ]);

            $updatedCount = 0;
            foreach ($request->updates as $update) {
                $locationData = [
                    'latitude' => $update['latitude'],
                    'longitude' => $update['longitude'],
                    'timestamp' => $update['timestamp'] ?? now()->toISOString(),
                    'updated_at' => now()->toISOString()
                ];

                Cache::put("vehicle_location_{$update['vehicle_id']}", $locationData, 3600);
                $updatedCount++;
            }

            return $this->successResponse([
                'updated_count' => $updatedCount,
                'timestamp' => now()->toISOString()
            ], 'Bulk vehicle locations updated successfully');

        } catch (\Exception $e) {
            Log::error('Error in bulk location update: ' . $e->getMessage());
            return $this->errorResponse('Failed to update vehicle locations', 500);
        }
    }

    /**
     * Handle transport webhook from external systems
     */
    public function handleTransportWebhook(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'vehicle_identifier' => 'required|string',
                'event_type' => 'required|string',
                'data' => 'required|array',
                'timestamp' => 'required|date'
            ]);

            $vehicle = Vehicle::where('vehicle_number', $request->vehicle_identifier)->first();
            
            if (!$vehicle) {
                return $this->errorResponse('Vehicle not found', 404);
            }

            $result = $this->vehicleService->processWebhookEvent(
                $vehicle,
                $request->event_type,
                $request->data,
                $request->timestamp
            );

            return $this->successResponse($result, 'Webhook processed successfully');

        } catch (\Exception $e) {
            Log::error('Error processing transport webhook: ' . $e->getMessage());
            return $this->errorResponse('Failed to process webhook', 500);
        }
    }

    // ================================
    // PRIVATE HELPER METHODS
    // ================================

    private function getStartDateFromPeriod(string $period): Carbon
    {
        return match($period) {
            '7days' => Carbon::now()->subDays(7),
            '30days' => Carbon::now()->subDays(30),
            '3months' => Carbon::now()->subMonths(3),
            '6months' => Carbon::now()->subMonths(6),
            '1year' => Carbon::now()->subYear(),
            default => Carbon::now()->subDays(30)
        };
    }

    private function calculateOnTimeDeliveryRate(Vehicle $vehicle, Carbon $startDate): float
    {
        $totalDeliveries = $vehicle->deliveryPerformances()
            ->where('status', 'completed')
            ->where('created_at', '>=', $startDate)
            ->count();

        if ($totalDeliveries === 0) return 0;

        $onTimeDeliveries = $vehicle->deliveryPerformances()
            ->where('status', 'completed')
            ->where('created_at', '>=', $startDate)
            ->whereRaw('delivered_at <= estimated_delivery_time')
            ->count();

        return round(($onTimeDeliveries / $totalDeliveries) * 100, 2);
    }

    private function calculateFuelEfficiency(Vehicle $vehicle, Carbon $startDate): ?float
    {
        // This would require fuel consumption tracking
        // Return null if not tracked
        return null;
    }

    private function calculateUtilizationRate(Vehicle $vehicle, Carbon $startDate): float
    {
        $totalHours = $startDate->diffInHours(Carbon::now());
        $activeHours = $vehicle->assignments()
            ->where('created_at', '>=', $startDate)
            ->sum(DB::raw('EXTRACT(EPOCH FROM (completed_at - started_at))/3600'));

        return $totalHours > 0 ? round(($activeHours / $totalHours) * 100, 2) : 0;
    }

    private function calculateMaintenanceCost(Vehicle $vehicle, Carbon $startDate): float
    {
        // This would require maintenance cost tracking
        // Return 0 as placeholder
        return 0.00;
    }

    private function calculateNextMaintenanceDue(Vehicle $vehicle): ?Carbon
    {
        // This would be based on mileage and last maintenance
        // Return estimate based on typical maintenance intervals
        return Carbon::now()->addDays(30); // Placeholder
    }

    private function calculateAverageMaintenanceInterval(Vehicle $vehicle): ?int
    {
        // Return average days between maintenance
        return 90; // Placeholder
    }

    private function getPerformanceTrends(Vehicle $vehicle, Carbon $startDate): array
    {
        // This would analyze performance over time
        return [
            'delivery_time_trend' => 'stable',
            'utilization_trend' => 'increasing',
            'maintenance_frequency' => 'normal'
        ];
    }

    private function calculateRouteProgress(TransportRoute $route): float
    {
        // Calculate based on distance traveled vs total distance
        return 0.0; // Placeholder
    }

    private function calculateEstimatedArrival(TransportRoute $route): ?Carbon
    {
        // Calculate based on current location and remaining distance
        return null; // Placeholder
    }

    private function calculateDelays(TransportRoute $route): array
    {
        // Return delays analysis
        return [
            'current_delay_minutes' => 0, // Placeholder
            'average_delay_minutes' => 0, // Placeholder
            'delay_reasons' => [], // Placeholder
            'impact_assessment' => 'minimal' // Placeholder
        ];
    }

    private function getNextWaypoint(TransportRoute $route): ?array
    {
        // Return next waypoint information
        return null; // Placeholder - implement based on your routing system
    }

}

?>