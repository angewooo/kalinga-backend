<?php

namespace App\Http\Controllers\API;

use App\Models\Hospital;
use App\Models\HospitalResource;
use App\Models\ResourceThreshold;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Hospital Management Controller for Project Kalinga
 * Handles hospital CRUD operations, resource management, and analytics
 */
class HospitalController extends BaseApiController
{
    /**
     * Get the model class for this controller
     */
    protected function getModel(): string
    {
        return Hospital::class;
    }

    /**
     * Get validation rules for hospital operations
     */
    protected function getValidationRules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'address' => 'required|string',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'capacity' => 'required|integer|min:1',
            'contact_number' => 'nullable|string|max:20',
            'facility_type' => 'nullable|string|max:50|in:government,private,specialty,emergency',
            'doh_classification' => 'nullable|string|max:20|in:level1,level2,level3,specialty',
            'status' => 'sometimes|string|in:active,inactive,maintenance,emergency',
        ];
    }

    /**
     * Get searchable fields
     */
    protected function getSearchableFields(): array
    {
        return ['name', 'address', 'facility_type', 'doh_classification'];
    }

    /**
     * Get default relationships to load
     */
    protected function getDefaultRelations(): array
    {
        return ['resources', 'responders', 'vehicles'];
    }

    /**
     * Display a listing of hospitals
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {

        try {

            // Simple fix: override ordering to use hospital_id
        $query = Hospital::with($this->getDefaultRelations())
            ->orderBy('hospital_id', 'desc');

        // Apply simple pagination
        $perPage = $request->get('per_page', 15);
        $hospitals = $query->paginate($perPage);

        // Add utilization percentage
        $hospitals->getCollection()->transform(function ($hospital) {
            $hospital->utilization_percentage = $hospital->capacity > 0 
                ? round(($hospital->current_load / $hospital->capacity) * 100, 2)
                : 0;
            return $hospital;
        });

        return response()->json([
            'success' => true,
            'data' => $hospitals->items(),
            'meta' => [
                'current_page' => $hospitals->currentPage(),
                'per_page' => $hospitals->perPage(),
                'total' => $hospitals->total(),
                'last_page' => $hospitals->lastPage()
            ],
            'message' => 'Hospitals retrieved successfully.'
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to retrieve hospitals',
            'error' => $e->getMessage()
        ], 500);

            // Validate query parameters
            $request->validate($this->commonRules + [
                'facility_type' => 'string|in:government,private,specialty,emergency',
                'doh_classification' => 'string|in:level1,level2,level3,specialty',
                'status' => 'string|in:active,inactive,maintenance,emergency',
                'latitude' => 'numeric|between:-90,90',
                'longitude' => 'numeric|between:-180,180',
                'radius' => 'numeric|min:0|max:100', // km
                'min_capacity' => 'integer|min:0',
                'max_capacity' => 'integer|min:0',
                'has_resources' => 'boolean'
            ]);

            $query = Hospital::with($this->getDefaultRelations());

            // Apply standard filters
            $query = $this->applyFilters($query, $request, $this->getSearchableFields());

            // Facility type filter
            if ($facilityType = $request->get('facility_type')) {
                $query->where('facility_type', $facilityType);
            }

            // DOH classification filter
            if ($classification = $request->get('doh_classification')) {
                $query->where('doh_classification', $classification);
            }

            // Capacity filters
            if ($minCapacity = $request->get('min_capacity')) {
                $query->where('capacity', '>=', $minCapacity);
            }
            
            if ($maxCapacity = $request->get('max_capacity')) {
                $query->where('capacity', '<=', $maxCapacity);
            }

            // Location-based filtering (within radius)
            if ($request->has(['latitude', 'longitude', 'radius'])) {
                $lat = $request->get('latitude');
                $lon = $request->get('longitude');
                $radius = $request->get('radius', 10); // default 10km

                $query->selectRaw("
                    *, 
                    (6371 * acos(
                        cos(radians(?)) * cos(radians(latitude)) * 
                        cos(radians(longitude) - radians(?)) + 
                        sin(radians(?)) * sin(radians(latitude))
                    )) AS distance
                ", [$lat, $lon, $lat])
                ->having('distance', '<=', $radius)
                ->orderBy('distance');
            }

            // Filter hospitals with resources
            if ($request->get('has_resources')) {
                $query->whereHas('resources');
            }

            // Pagination
            $params = $this->getPaginationParams($request);
            $hospitals = $query->paginate($params['per_page']);

            // Add utilization percentage to each hospital
            $hospitals->getCollection()->transform(function ($hospital) {
                $hospital->utilization_percentage = $hospital->capacity > 0 
                    ? round(($hospital->current_load / $hospital->capacity) * 100, 2)
                    : 0;
                return $hospital;
            });

            $this->logActivity('Hospitals listed', [
                'total' => $hospitals->total(),
                'filters' => $request->only(['facility_type', 'status', 'search'])
            ]);

            return $this->paginatedResponse($hospitals, 'Hospitals retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'listing hospitals');
        }
    }

    /**
     * Store a newly created hospital
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        try {
            if (!$this->userCan('create-hospitals')) {
                return $this->forbiddenResponse('You do not have permission to create hospitals.');
            }

            $validated = $request->validate($this->getValidationRules());

            // Validate coordinates
            if (!$this->validateCoordinates($validated['latitude'], $validated['longitude'])) {
                return $this->errorResponse('Invalid coordinates provided.', 400);
            }

            DB::beginTransaction();

            $hospital = Hospital::create([
                ...$validated,
                'current_load' => 0,
                'status' => $validated['status'] ?? 'active'
            ]);

            DB::commit();

            $hospital->load($this->getDefaultRelations());

            $this->logActivity('Hospital created', [
                'hospital_id' => $hospital->hospital_id,
                'name' => $hospital->name,
                'facility_type' => $hospital->facility_type
            ]);

            return $this->createdResponse($hospital, 'Hospital created successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->handleException($e, 'creating hospital');
        }
    }

    /**
     * Display the specified hospital
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            $hospital = Hospital::with([
                'resources',
                'responders',
                'vehicles'
            ])->findOrFail($id);

            // Calculate additional metrics
            $hospital->utilization_percentage = $hospital->capacity > 0 
                ? round(($hospital->current_load / $hospital->capacity) * 100, 2)
                : 0;

            $hospital->resource_count = $hospital->resources->count();
            $hospital->available_responders = $hospital->responders->where('status', 'available')->count();
            $hospital->available_vehicles = $hospital->vehicles->where('status', 'available')->count();

            // Simple low stock calculation
            $hospital->low_stock_alerts = $hospital->resources()
                ->where('quantity_available', '<=', DB::raw('quantity_total * 0.1'))
                ->count();

            $this->logActivity('Hospital viewed', ['hospital_id' => $hospital->hospital_id]);

            return $this->successResponse($hospital, 'Hospital retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieving hospital');
        }
    }

    /**
     * Update the specified hospital
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            if (!$this->userCan('update-hospitals')) {
                return $this->forbiddenResponse('You do not have permission to update hospitals.');
            }

            $hospital = Hospital::findOrFail($id);

            // Make all fields optional for updates
            $rules = $this->getValidationRules();
            foreach ($rules as $key => $rule) {
                $rules[$key] = str_replace('required|', 'sometimes|', $rule);
            }

            $validated = $request->validate($rules);

            // Validate coordinates if provided
            if (isset($validated['latitude'], $validated['longitude'])) {
                if (!$this->validateCoordinates($validated['latitude'], $validated['longitude'])) {
                    return $this->errorResponse('Invalid coordinates provided.', 400);
                }
            }

            $hospital->update($validated);
            $hospital->load($this->getDefaultRelations());

            $this->logActivity('Hospital updated', [
                'hospital_id' => $hospital->hospital_id,
                'updated_fields' => array_keys($validated)
            ]);

            return $this->updatedResponse($hospital, 'Hospital updated successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'updating hospital');
        }
    }

    /**
     * Remove the specified hospital
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            if (!$this->userCan('delete-hospitals')) {
                return $this->forbiddenResponse('You do not have permission to delete hospitals.');
            }

            $hospital = Hospital::findOrFail($id);

            // Check for active assignments or critical resources
            if ($this->hospitalHasActiveOperations($hospital)) {
                return $this->errorResponse(
                    'Hospital has active operations and cannot be deleted. Please resolve all active requests first.', 
                    409
                );
            }

            $hospital->delete();

            $this->logActivity('Hospital deleted', ['hospital_id' => $hospital->hospital_id]);

            return $this->deletedResponse('Hospital deleted successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'deleting hospital');
        }
    }

    /**
     * Get hospital resources
     *
     * @param int $id
     * @return JsonResponse
     */
    public function resources(int $id): JsonResponse
    {
        try {
            $hospital = Hospital::findOrFail($id);
            
            $resources = $hospital->resources()
                ->get()
                ->map(function ($resource) {
                    $resource->status = $this->getResourceStatus($resource);
                    return $resource;
                });

            return $this->successResponse([
                'hospital_id' => $hospital->hospital_id,
                'hospital_name' => $hospital->name,
                'resources' => $resources,
                'summary' => [
                    'total_resources' => $resources->count(),
                    'low_stock' => $resources->where('status', 'low_stock')->count(),
                    'out_of_stock' => $resources->where('status', 'out_of_stock')->count(),
                ]
            ], 'Hospital resources retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieving hospital resources');
        }
    }

    /**
     * Add a resource to hospital
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
public function addResource(Request $request, int $id): JsonResponse
{
    try {
        \Log::info('addResource - Starting', ['hospital_id' => $id, 'user_id' => $this->currentUser()]);

        // Check permission with detailed logging
        if (!$this->userCan('manage-resources')) {
            \Log::warning('addResource - Permission denied', [
                'user_id' => $this->currentUser(),
                'permission' => 'manage-resources',
                'user_has_permission' => auth()->user()->hasPermissionTo('manage-resources', 'sanctum')
            ]);
            return $this->forbiddenResponse('You do not have permission to manage resources.');
        }

        \Log::info('addResource - Permission granted');

        $hospital = Hospital::findOrFail($id);
        \Log::info('addResource - Hospital found', ['hospital_id' => $hospital->hospital_id]);

        $validated = $request->validate([
            'resource_type' => 'required|string|max:50',
            'quantity_total' => 'required|integer|min:0',
            'quantity_available' => 'required|integer|min:0|lte:quantity_total',
            'unit' => 'nullable|string|max:20',
            'cost_per_unit' => 'nullable|numeric|min:0',
        ]);

        \Log::info('addResource - Validation passed', $validated);

        DB::beginTransaction();
        \Log::info('addResource - Transaction started');

        $resource = HospitalResource::create([
            'hospital_id' => $hospital->hospital_id,
            'resource_type' => $validated['resource_type'],
            'quantity_total' => $validated['quantity_total'],
            'quantity_available' => $validated['quantity_available'],
            'unit' => $validated['unit'],
            'cost_per_unit' => $validated['cost_per_unit'],
            'total_cost' => ($validated['cost_per_unit'] ?? 0) * $validated['quantity_total']
        ]);

        \Log::info('addResource - Resource created', ['resource_id' => $resource->resource_id]);

        DB::commit();
        \Log::info('addResource - Transaction committed');

        return response()->json([
            'success' => true,
            'data' => $resource,
            'message' => 'Resource added to hospital successfully.'
        ], 201);

    } catch (\Exception $e) {
        DB::rollBack();
        \Log::error('addResource - Exception occurred', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'hospital_id' => $id,
            'user_id' => $this->currentUser()
        ]);
        return response()->json([
            'success' => false,
            'message' => 'Failed to add resource to hospital',
            'error' => $e->getMessage()
        ], 500);
    }
}



    /**
     * Update hospital resource
     *
     * @param Request $request
     * @param int $hospitalId
     * @param int $resourceId
     * @return JsonResponse
     */
    public function updateResource(Request $request, int $hospitalId, int $resourceId): JsonResponse
    {
        try {
            if (!$this->userCan('manage-resources')) {
                return $this->forbiddenResponse('You do not have permission to manage resources.');
            }

            $hospital = Hospital::findOrFail($hospitalId);
            $resource = $hospital->resources()->findOrFail($resourceId);

            $validated = $request->validate([
                'quantity_total' => 'sometimes|integer|min:0',
                'quantity_available' => 'sometimes|integer|min:0',
                'unit' => 'sometimes|string|max:20',
                'cost_per_unit' => 'sometimes|numeric|min:0',
            ]);

            // Validate quantity_available doesn't exceed quantity_total
            if (isset($validated['quantity_available']) && isset($validated['quantity_total'])) {
                if ($validated['quantity_available'] > $validated['quantity_total']) {
                    return $this->errorResponse('Available quantity cannot exceed total quantity.', 400);
                }
            } elseif (isset($validated['quantity_available'])) {
                if ($validated['quantity_available'] > $resource->quantity_total) {
                    return $this->errorResponse('Available quantity cannot exceed total quantity.', 400);
                }
            }

            // Update total cost if cost_per_unit or quantity_total changed
            if (isset($validated['cost_per_unit']) || isset($validated['quantity_total'])) {
                $costPerUnit = $validated['cost_per_unit'] ?? $resource->cost_per_unit;
                $quantityTotal = $validated['quantity_total'] ?? $resource->quantity_total;
                $validated['total_cost'] = $costPerUnit * $quantityTotal;
            }

            $resource->update($validated);

            $this->logActivity('Hospital resource updated', [
                'hospital_id' => $hospital->hospital_id,
                'resource_id' => $resource->resource_id,
                'updated_fields' => array_keys($validated)
            ]);

            return $this->updatedResponse($resource->load('thresholds'), 'Resource updated successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'updating hospital resource');
        }
    }

    /**
     * Remove resource from hospital
     *
     * @param int $hospitalId
     * @param int $resourceId
     * @return JsonResponse
     */
    public function removeResource(int $hospitalId, int $resourceId): JsonResponse
    {
        try {
            if (!$this->userCan('manage-resources')) {
                return $this->forbiddenResponse('You do not have permission to manage resources.');
            }

            $hospital = Hospital::findOrFail($hospitalId);
            $resource = $hospital->resources()->findOrFail($resourceId);

            // Check if resource is involved in active allocations
            if ($this->hasActiveAllocations($resource)) {
                return $this->errorResponse(
                    'Resource has pending allocations and cannot be removed.', 
                    409
                );
            }

            $resource->delete();

            $this->logActivity('Resource removed from hospital', [
                'hospital_id' => $hospital->hospital_id,
                'resource_id' => $resourceId
            ]);

            return $this->deletedResponse('Resource removed successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'removing hospital resource');
        }
    }

    /**
     * Get hospital capacity information
     *
     * @param int $id
     * @return JsonResponse
     */
    public function capacity(int $id): JsonResponse
    {
        try {
            $hospital = Hospital::findOrFail($id);

            $capacityData = [
                'hospital_id' => $hospital->hospital_id,
                'hospital_name' => $hospital->name,
                'total_capacity' => $hospital->capacity,
                'current_load' => $hospital->current_load,
                'available_capacity' => $hospital->capacity - $hospital->current_load,
                'utilization_percentage' => $hospital->capacity > 0 
                    ? round(($hospital->current_load / $hospital->capacity) * 100, 2)
                    : 0,
                'status' => $this->getCapacityStatus($hospital),
                'last_updated' => $hospital->updated_at
            ];

            return $this->successResponse($capacityData, 'Hospital capacity retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieving hospital capacity');
        }
    }

    /**
     * Get hospital utilization metrics
     *
     * @param int $id
     * @return JsonResponse
     */
    public function utilization(int $id): JsonResponse
    {
        try {
            $hospital = Hospital::with(['resources', 'responders', 'vehicles'])->findOrFail($id);

            $utilizationData = [
                'hospital_id' => $hospital->hospital_id,
                'hospital_name' => $hospital->name,
                'bed_utilization' => [
                    'total_beds' => $hospital->capacity,
                    'occupied_beds' => $hospital->current_load,
                    'utilization_percentage' => $hospital->capacity > 0 
                        ? round(($hospital->current_load / $hospital->capacity) * 100, 2)
                        : 0
                ],
                'staff_utilization' => [
                    'total_responders' => $hospital->responders->count(),
                    'available_responders' => $hospital->responders->where('status', 'available')->count(),
                    'on_duty_responders' => $hospital->responders->where('status', 'on_duty')->count()
                ],
                'vehicle_utilization' => [
                    'total_vehicles' => $hospital->vehicles->count(),
                    'available_vehicles' => $hospital->vehicles->where('status', 'available')->count(),
                    'in_use_vehicles' => $hospital->vehicles->where('status', 'in_use')->count()
                ],
                'resource_status' => $this->getResourceUtilizationSummary($hospital)
            ];

            return $this->successResponse($utilizationData, 'Hospital utilization metrics retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieving hospital utilization');
        }
    }

    /**
     * Get hospital performance metrics
     *
     * @param int $id
     * @return JsonResponse
     */
    public function performance(int $id): JsonResponse
    {
        try {
            $hospital = Hospital::findOrFail($id);

            // This would typically query historical data and performance metrics
            $performanceData = [
                'hospital_id' => $hospital->hospital_id,
                'hospital_name' => $hospital->name,
                'response_time_avg' => $this->calculateAverageResponseTime($hospital),
                'completion_rate' => $this->calculateCompletionRate($hospital),
                'resource_efficiency' => $this->calculateResourceEfficiency($hospital),
                'patient_satisfaction' => $this->getPatientSatisfactionScore($hospital),
                'operational_score' => $this->calculateOperationalScore($hospital),
                'last_30_days' => [
                    'total_requests' => $this->getRequestCount($hospital, 30),
                    'completed_requests' => $this->getCompletedRequestCount($hospital, 30),
                    'average_response_time' => $this->getAverageResponseTime($hospital, 30)
                ]
            ];

            return $this->successResponse($performanceData, 'Hospital performance metrics retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieving hospital performance');
        }
    }

    /**
     * Get hospital responders
     *
     * @param int $id
     * @return JsonResponse
     */
    public function responders(int $id): JsonResponse
    {
        try {
            $hospital = Hospital::findOrFail($id);
            
            $responders = $hospital->responders()
                ->with(['user.profile', 'assignments' => function($q) {
                    $q->where('status', 'assigned')->orWhere('status', 'in_progress');
                }])
                ->get()
                ->map(function ($responder) {
                    $responder->active_assignments = $responder->assignments->count();
                    return $responder;
                });

            return $this->successResponse([
                'hospital_id' => $hospital->hospital_id,
                'hospital_name' => $hospital->name,
                'responders' => $responders,
                'summary' => [
                    'total_responders' => $responders->count(),
                    'available' => $responders->where('status', 'available')->count(),
                    'on_duty' => $responders->where('status', 'on_duty')->count(),
                    'off_duty' => $responders->where('status', 'off_duty')->count()
                ]
            ], 'Hospital responders retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieving hospital responders');
        }
    }

    /**
     * Get hospital vehicles
     *
     * @param int $id
     * @return JsonResponse
     */
    public function vehicles(int $id): JsonResponse
    {
        try {
            $hospital = Hospital::findOrFail($id);
            
            $vehicles = $hospital->vehicles;

            return $this->successResponse([
                'hospital_id' => $hospital->hospital_id,
                'hospital_name' => $hospital->name,
                'vehicles' => $vehicles,
                'summary' => [
                    'total_vehicles' => $vehicles->count(),
                    'available' => $vehicles->where('status', 'available')->count(),
                    'in_use' => $vehicles->where('status', 'in_use')->count(),
                    'maintenance' => $vehicles->where('status', 'maintenance')->count()
                ]
            ], 'Hospital vehicles retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieving hospital vehicles');
        }
    }

    /**
     * Find nearby hospitals
     *
     * @param Request $request
     * @return JsonResponse
     */
 public function nearby(Request $request): JsonResponse
{
    try {
        // Basic validation
        $validated = $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'radius' => 'sometimes|numeric|min:1|max:100',
            'limit' => 'sometimes|integer|min:1|max:50',
        ]);

        // Simple query - return active hospitals without complex distance calculation
        $hospitals = Hospital::where('status', 'active')
            ->orderBy('hospital_id', 'desc')
            ->limit($validated['limit'] ?? 10)
            ->get(['hospital_id', 'name', 'address', 'latitude', 'longitude', 'capacity', 'current_load', 'facility_type']);

        // Add simple metrics and mock distance
        $hospitals->transform(function ($hospital) use ($validated) {
            $hospital->utilization_percentage = $hospital->capacity > 0 
                ? round(($hospital->current_load / $hospital->capacity) * 100, 2)
                : 0;
            // Mock distance for now (would be calculated with proper geospatial query)
            $hospital->distance_km = rand(1, 50);
            return $hospital;
        });

        return response()->json([
            'success' => true,
            'data' => [
                'search_location' => [
                    'latitude' => $validated['latitude'],
                    'longitude' => $validated['longitude'],
                    'radius_km' => $validated['radius'] ?? 25
                ],
                'hospitals' => $hospitals,
                'total_found' => $hospitals->count()
            ],
            'message' => 'Nearby hospitals retrieved successfully.'
        ]);

    } catch (\Exception $e) {
        \Log::error('Nearby hospitals failed', [
            'error' => $e->getMessage(),
            'latitude' => $request->get('latitude'),
            'longitude' => $request->get('longitude'),
            'trace' => $e->getTraceAsString()
        ]);
        return response()->json([
            'success' => false,
            'message' => 'Failed to find nearby hospitals',
            'error' => $e->getMessage()
        ], 500);
    }
}
    /**
     * Search hospitals with advanced filters
     *
     * @param Request $request
     * @return JsonResponse
     */
 public function search(Request $request): JsonResponse
{
    try {
        // Basic validation
        if (!$request->has('query') || strlen($request->get('query')) < 2) {
            return response()->json([
                'success' => false,
                'message' => 'Query parameter required with minimum 2 characters'
            ], 400);
        }

        $query = $request->get('query');
        
        // Simple search without complex relationships or ordering
        $hospitals = Hospital::where('name', 'ILIKE', "%{$query}%")
            ->orWhere('address', 'ILIKE', "%{$query}%")
            ->where('status', 'active')
            ->limit(10)
            ->get(['hospital_id', 'name', 'address', 'capacity', 'current_load', 'facility_type']);

        // Add simple metrics
        $hospitals->transform(function ($hospital) {
            $hospital->utilization_percentage = $hospital->capacity > 0 
                ? round(($hospital->current_load / $hospital->capacity) * 100, 2)
                : 0;
            return $hospital;
        });

        return response()->json([
            'success' => true,
            'data' => [
                'hospitals' => $hospitals,
                'total_found' => $hospitals->count()
            ],
            'message' => 'Hospital search completed successfully.'
        ]);

    } catch (\Exception $e) {
        \Log::error('Search hospitals failed', [
            'error' => $e->getMessage(),
            'query' => $request->get('query'),
            'trace' => $e->getTraceAsString()
        ]);
        return response()->json([
            'success' => false,
            'message' => 'Failed to search hospitals',
            'error' => $e->getMessage()
        ], 500);
    }
}


    /*******************************
     * PROTECTED / HELPER METHODS
     *******************************/

    /**
     * Get resource status (low_stock, out_of_stock, normal)
     */
    protected function getResourceStatus(HospitalResource $resource): string
    {
        if ($resource->quantity_available <= 0) return 'out_of_stock';

        // Simple threshold calculation instead of using thresholds table
        $lowStockThreshold = $resource->quantity_total * 0.1; // 10% of total
        if ($resource->quantity_available <= $lowStockThreshold) return 'low_stock';

        return 'normal';
    }

    /**
     * Calculate days until resource expiry (example)
     */
    protected function calculateDaysUntilExpiry(HospitalResource $resource): ?int
    {
        // Simplified - return null for now since batches relationship doesn't exist
        return null;
    }

    /**
     * Summarize resource utilization for hospital
     */
    protected function getResourceUtilizationSummary(Hospital $hospital): array
    {
        $resources = $hospital->resources;
        
        return [
            'total_resources' => $resources->count(),
            'low_stock' => $resources->filter(fn($r) => $this->getResourceStatus($r) === 'low_stock')->count(),
            'out_of_stock' => $resources->filter(fn($r) => $this->getResourceStatus($r) === 'out_of_stock')->count()
        ];
    }

    /**
     * Check if hospital has active operations (requests/assignments)
     */
    protected function hospitalHasActiveOperations(Hospital $hospital): bool
    {
        return $hospital->requests()->whereIn('status', ['pending', 'in_progress'])->exists();
    }

    /**
     * Check if resource has active allocations
     */
    protected function hasActiveAllocations(HospitalResource $resource): bool
    {
        return $resource->allocations()->whereIn('status', ['pending', 'in_progress'])->exists();
    }

    /**
     * Validate coordinates
     */
    protected function validateCoordinates(?float $lat, ?float $lon): bool
    {
        if ($lat === null || $lon === null) {
            return false;
        }

        return $lat >= -90 && $lat <= 90 && $lon >= -180 && $lon <= 180;
    }

    /**
     * Get capacity status
     */
    protected function getCapacityStatus(Hospital $hospital): string
    {
        $util = $hospital->capacity > 0 ? ($hospital->current_load / $hospital->capacity) * 100 : 0;
        if ($util >= 100) return 'full';
        if ($util >= 75) return 'high';
        if ($util >= 50) return 'medium';
        return 'low';
    }

    /**
     * Get availability status for nearby/search results
     */
    protected function getAvailabilityStatus(Hospital $hospital): string
    {
        $util = $hospital->capacity > 0 ? ($hospital->current_load / $hospital->capacity) * 100 : 0;
        if ($util >= 100) return 'full';
        if ($util >= 75) return 'high';
        if ($util >= 50) return 'medium';
        return 'low';
    }

    /**
     * Get hospital thresholds (placeholder)
     */
    public function getThresholds(int $id): JsonResponse
    {
        return $this->successResponse([], 'Thresholds endpoint - to be implemented');
    }

    /**
     * Set hospital threshold (placeholder)
     */
    public function setThreshold(Request $request, int $id): JsonResponse
    {
        return $this->successResponse([], 'Set threshold endpoint - to be implemented');
    }

    /**
     * Update hospital threshold (placeholder)
     */
    public function updateThreshold(Request $request, int $id, int $thresholdId): JsonResponse
    {
        return $this->successResponse([], 'Update threshold endpoint - to be implemented');
    }

    /**
     * Get hospital inventory (placeholder)
     */
    public function getInventory(int $id): JsonResponse
    {
        return $this->successResponse([], 'Inventory endpoint - to be implemented');
    }

    /**
     * Get inventory logs (placeholder)
     */
    public function getInventoryLogs(int $id): JsonResponse
    {
        return $this->successResponse([], 'Inventory logs endpoint - to be implemented');
    }

    /**
     * Adjust inventory (placeholder)
     */
    public function adjustInventory(Request $request, int $id): JsonResponse
    {
        return $this->successResponse([], 'Adjust inventory endpoint - to be implemented');
    }

    /**
     * Get hospital analytics (placeholder)
     */
    public function getAnalytics(int $id): JsonResponse
    {
        return $this->successResponse([], 'Analytics endpoint - to be implemented');
    }

    /**
     * Get request history (placeholder)
     */
    public function getRequestHistory(int $id): JsonResponse
    {
        return $this->successResponse([], 'Request history endpoint - to be implemented');
    }

    /**
     * Bulk create hospitals (placeholder)
     */
    public function bulkCreate(Request $request): JsonResponse
    {
        return $this->successResponse([], 'Bulk create endpoint - to be implemented');
    }

    /**
     * Bulk update hospitals (placeholder)
     */
    public function bulkUpdate(Request $request): JsonResponse
    {
        return $this->successResponse([], 'Bulk update endpoint - to be implemented');
    }

    /**
     * Import resources (placeholder)
     */
    public function importResources(Request $request): JsonResponse
    {
        return $this->successResponse([], 'Import resources endpoint - to be implemented');
    }

    /**
     * Export inventory (placeholder)
     */
    public function exportInventory(Request $request): JsonResponse
    {
        return $this->successResponse([], 'Export inventory endpoint - to be implemented');
    }



    // Placeholder performance metrics (to be implemented with actual data)
    protected function calculateAverageResponseTime(Hospital $hospital): float { return 0; }
    protected function calculateCompletionRate(Hospital $hospital): float { return 0; }
    protected function calculateResourceEfficiency(Hospital $hospital): float { return 0; }
    protected function getPatientSatisfactionScore(Hospital $hospital): float { return 0; }
    protected function calculateOperationalScore(Hospital $hospital): float { return 0; }
    protected function getRequestCount(Hospital $hospital, int $days): int { return 0; }
    protected function getCompletedRequestCount(Hospital $hospital, int $days): int { return 0; }
    protected function getAverageResponseTime(Hospital $hospital, int $days): float { return 0; }
}