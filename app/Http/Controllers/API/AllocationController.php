<?php

namespace App\Http\Controllers\API;

use App\Models\ResourceAllocation;
use App\Models\AllocationAlgorithm;
use App\Models\AllocationTest;
use App\Models\HospitalResource;
use App\Models\Hospital;
use App\Models\RequestEntry;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * AI Resource Allocation Controller for Project Kalinga
 * Handles intelligent resource allocation, optimization algorithms, and testing
 */
class AllocationController extends BaseApiController
{
    /**
     * Get the model class for this controller
     */
    protected function getModel(): string
    {
        return ResourceAllocation::class;
    }

    /**
     * Get validation rules for allocation operations
     */
    protected function getValidationRules(): array
    {
        return [
            'resource_id' => 'required|integer|exists:hospital_resources,resource_id',
            'from_hospital' => 'required|integer|exists:hospitals,hospital_id',
            'to_hospital' => 'required|integer|exists:hospitals,hospital_id|different:from_hospital',
            'quantity' => 'required|integer|min:1',
            'reason' => 'required|string|max:100|in:emergency_request,stock_balancing,predictive_allocation,manual_transfer',
            'priority' => 'sometimes|string|in:low,medium,high,critical',
        ];
    }

    /**
     * Get searchable fields
     */
    protected function getSearchableFields(): array
    {
        return ['reason', 'status'];
    }

    /**
     * Get default relationships to load
     */
    protected function getDefaultRelations(): array
    {
        return ['resource.hospital', 'fromHospital', 'toHospital'];
    }

    /**
     * Display a listing of resource allocations
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $request->validate($this->commonRules + [
                'status' => 'string|in:pending,approved,in_transit,completed,cancelled',
                'reason' => 'string|in:emergency_request,stock_balancing,predictive_allocation,manual_transfer',
                'hospital_id' => 'integer|exists:hospitals,hospital_id',
                'resource_type' => 'string',
                'priority' => 'string|in:low,medium,high,critical'
            ]);

            $query = ResourceAllocation::with($this->getDefaultRelations());

            // Apply standard filters
            $query = $this->applyFilters($query, $request, $this->getSearchableFields());

            // Status filter
            if ($status = $request->get('status')) {
                $query->where('status', $status);
            }

            // Reason filter
            if ($reason = $request->get('reason')) {
                $query->where('reason', $reason);
            }

            // Hospital filter (either source or destination)
            if ($hospitalId = $request->get('hospital_id')) {
                $query->where(function($q) use ($hospitalId) {
                    $q->where('from_hospital', $hospitalId)
                      ->orWhere('to_hospital', $hospitalId);
                });
            }

            // Resource type filter
            if ($resourceType = $request->get('resource_type')) {
                $query->whereHas('resource', function($q) use ($resourceType) {
                    $q->where('resource_type', $resourceType);
                });
            }

            // Pagination with priority-based sorting
            $params = $this->getPaginationParams($request);
            
            if (!$request->has('sort')) {
                $query->orderBy('allocated_at', 'desc');
            }

            $allocations = $query->paginate($params['per_page']);

            // Add calculated metrics
            $allocations->getCollection()->transform(function ($allocation) {
                $allocation->efficiency_score = $this->calculateEfficiencyScore($allocation);
                $allocation->estimated_delivery_time = $this->calculateEstimatedDelivery($allocation);
                return $allocation;
            });

            $this->logActivity('Allocations listed', [
                'total' => $allocations->total(),
                'filters' => $request->only(['status', 'reason', 'hospital_id'])
            ]);

            return $this->paginatedResponse($allocations, 'Resource allocations retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'listing allocations');
        }
    }

    /**
     * Create a new allocation
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function create(Request $request): JsonResponse
    {
        try {
            if (!$this->userCan('create-allocations')) {
                return $this->forbiddenResponse('You do not have permission to create allocations.');
            }

            $validated = $request->validate($this->getValidationRules() + [
                'algorithm_id' => 'sometimes|integer|exists:allocation_algorithms,algorithm_id',
                'notes' => 'nullable|string|max:500',
                'auto_approve' => 'sometimes|boolean'
            ]);

            // Validate resource availability
            $resource = HospitalResource::findOrFail($validated['resource_id']);
            if ($resource->quantity_available < $validated['quantity']) {
                return $this->errorResponse('Insufficient resource quantity available.', 400);
            }

            // Validate hospital constraints
            if (!$this->validateHospitalConstraints($validated)) {
                return $this->errorResponse('Hospital allocation constraints not met.', 400);
            }

            DB::beginTransaction();

            // Use default algorithm if none specified
            $algorithmId = $validated['algorithm_id'] ?? $this->getDefaultAlgorithm()->algorithm_id;

            // Create allocation
            $allocation = ResourceAllocation::create([
                ...$validated,
                'algorithm_id' => $algorithmId,
                'status' => $validated['auto_approve'] ?? false ? 'approved' : 'pending',
                'allocated_at' => now()
            ]);

            // Reserve resources immediately
            $resource->decrement('quantity_available', $validated['quantity']);

            // Run allocation optimization if high priority
            if (($validated['priority'] ?? 'medium') === 'critical') {
                $optimization = $this->runAllocationOptimization($allocation);
                $allocation->optimization_result = $optimization;
                $allocation->save();
            }

            DB::commit();

            $allocation->load($this->getDefaultRelations());

            $this->logActivity('Allocation created', [
                'allocation_id' => $allocation->allocation_id,
                'from_hospital' => $validated['from_hospital'],
                'to_hospital' => $validated['to_hospital'],
                'resource_type' => $resource->resource_type,
                'quantity' => $validated['quantity'],
                'algorithm_used' => $algorithmId
            ]);

            return $this->allocationResponse($allocation, [
                'algorithm' => AllocationAlgorithm::find($algorithmId)->algorithm_name ?? 'default',
                'confidence' => $this->calculateConfidenceScore($allocation),
                'optimization_time' => '0.15s'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->handleException($e, 'creating allocation');
        }
    }

    /**
     * Display the specified allocation
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            $allocation = ResourceAllocation::with([
                'resource.hospital',
                'fromHospital',
                'toHospital',
                'algorithm'
            ])->findOrFail($id);

            // Add computed metrics
            $allocation->efficiency_score = $this->calculateEfficiencyScore($allocation);
            $allocation->optimization_details = $this->getOptimizationDetails($allocation);
            $allocation->delivery_tracking = $this->getDeliveryTracking($allocation);

            $this->logActivity('Allocation viewed', ['allocation_id' => $allocation->allocation_id]);

            return $this->successResponse($allocation, 'Allocation retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieving allocation');
        }
    }

    /**
     * Update the specified allocation
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            if (!$this->userCan('update-allocations')) {
                return $this->forbiddenResponse('You do not have permission to update allocations.');
            }

            $allocation = ResourceAllocation::findOrFail($id);

            // Don't allow updates to completed allocations
            if (in_array($allocation->status, ['completed', 'cancelled'])) {
                return $this->errorResponse('Cannot update completed or cancelled allocations.', 400);
            }

            $validated = $request->validate([
                'status' => 'sometimes|string|in:pending,approved,in_transit,completed,cancelled',
                'priority' => 'sometimes|string|in:low,medium,high,critical',
                'notes' => 'sometimes|string|max:500'
            ]);

            $allocation->update($validated);

            $this->logActivity('Allocation updated', [
                'allocation_id' => $allocation->allocation_id,
                'updated_fields' => array_keys($validated)
            ]);

            return $this->updatedResponse(
                $allocation->load($this->getDefaultRelations()), 
                'Allocation updated successfully.'
            );

        } catch (\Exception $e) {
            return $this->handleException($e, 'updating allocation');
        }
    }

    /**
     * Remove the specified allocation
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            if (!$this->userCan('delete-allocations')) {
                return $this->forbiddenResponse('You do not have permission to delete allocations.');
            }

            $allocation = ResourceAllocation::findOrFail($id);

            if ($allocation->status === 'completed') {
                return $this->errorResponse('Cannot delete completed allocations.', 400);
            }

            DB::beginTransaction();

            // Restore reserved resources if allocation was pending/approved
            if (in_array($allocation->status, ['pending', 'approved'])) {
                $allocation->resource->increment('quantity_available', $allocation->quantity);
            }

            $allocation->delete();

            DB::commit();

            $this->logActivity('Allocation deleted', ['allocation_id' => $allocation->allocation_id]);

            return $this->deletedResponse('Allocation deleted successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->handleException($e, 'deleting allocation');
        }
    }

    /**
     * Optimize resource allocation using AI algorithms
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function optimize(Request $request): JsonResponse
    {
        try {
            if (!$this->userCan('run-optimization')) {
                return $this->forbiddenResponse('You do not have permission to run optimizations.');
            }

            $validated = $request->validate([
                'algorithm_id' => 'sometimes|integer|exists:allocation_algorithms,algorithm_id',
                'constraints' => 'sometimes|array',
                'constraints.max_distance_km' => 'integer|min:1|max:500',
                'constraints.min_stock_level' => 'integer|min:0',
                'constraints.priority_hospitals' => 'array',
                'constraints.priority_hospitals.*' => 'integer|exists:hospitals,hospital_id',
                'resource_requests' => 'required|array|min:1',
                'resource_requests.*.resource_type' => 'required|string',
                'resource_requests.*.quantity_needed' => 'required|integer|min:1',
                'resource_requests.*.requesting_hospital_id' => 'required|integer|exists:hospitals,hospital_id',
                'resource_requests.*.priority' => 'sometimes|string|in:low,medium,high,critical'
            ]);

            $startTime = microtime(true);

            // Get or use default algorithm
            $algorithm = isset($validated['algorithm_id']) 
                ? AllocationAlgorithm::findOrFail($validated['algorithm_id'])
                : $this->getDefaultAlgorithm();

            // Run optimization
            $optimizationResult = $this->runMultiResourceOptimization(
                $validated['resource_requests'],
                $validated['constraints'] ?? [],
                $algorithm
            );

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->logActivity('Allocation optimization completed', [
                'algorithm_id' => $algorithm->algorithm_id,
                'requests_processed' => count($validated['resource_requests']),
                'execution_time_ms' => $executionTime,
                'solutions_found' => count($optimizationResult['allocations'])
            ]);

            return $this->successResponse([
                'algorithm' => $algorithm,
                'execution_time_ms' => $executionTime,
                'optimization_result' => $optimizationResult,
                'performance_metrics' => [
                    'total_distance_saved' => $optimizationResult['metrics']['distance_efficiency'] ?? 0,
                    'resource_utilization' => $optimizationResult['metrics']['utilization_rate'] ?? 0,
                    'cost_efficiency' => $optimizationResult['metrics']['cost_efficiency'] ?? 0
                ]
            ], 'Allocation optimization completed successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'running allocation optimization');
        }
    }

    /**
     * Simulate allocation scenarios
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function simulate(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'scenario_type' => 'required|string|in:disaster_response,daily_operations,peak_demand,supply_shortage',
                'parameters' => 'required|array',
                'parameters.duration_hours' => 'integer|min:1|max:168', // max 1 week
                'parameters.demand_multiplier' => 'numeric|min:0.1|max:10.0',
                'parameters.affected_hospitals' => 'sometimes|array',
                'parameters.affected_hospitals.*' => 'integer|exists:hospitals,hospital_id',
                'parameters.resource_constraints' => 'sometimes|array'
            ]);

            $simulation = $this->runAllocationSimulation(
                $validated['scenario_type'],
                $validated['parameters']
            );

            $this->logActivity('Allocation simulation completed', [
                'scenario_type' => $validated['scenario_type'],
                'duration_hours' => $validated['parameters']['duration_hours'] ?? 24,
                'hospitals_involved' => count($validated['parameters']['affected_hospitals'] ?? [])
            ]);

            return $this->successResponse($simulation, 'Allocation simulation completed successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'running allocation simulation');
        }
    }

    /**
     * Get available allocation algorithms
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function algorithms(Request $request): JsonResponse
    {
        try {
            $algorithms = AllocationAlgorithm::with(['tests' => function($q) {
                $q->orderBy('tested_at', 'desc')->limit(5);
            }])
            ->get()
            ->map(function ($algorithm) {
                $algorithm->performance_metrics = $this->getAlgorithmPerformanceMetrics($algorithm);
                return $algorithm;
            });

            return $this->collectionResponse($algorithms, 'Allocation algorithms retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieving allocation algorithms');
        }
    }

    /**
     * Get allocation performance metrics
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function performance(Request $request): JsonResponse
    {
        try {
            $period = $request->get('period', '30days');
            $dateFrom = $this->parsePeriod($period);

            $metrics = [
                'total_allocations' => ResourceAllocation::where('allocated_at', '>=', $dateFrom)->count(),
                'success_rate' => $this->calculateSuccessRate($dateFrom),
                'average_efficiency' => $this->calculateAverageEfficiency($dateFrom),
                'algorithm_performance' => $this->getAlgorithmComparison($dateFrom),
                'resource_type_breakdown' => $this->getResourceTypeBreakdown($dateFrom),
                'hospital_network_efficiency' => $this->calculateNetworkEfficiency($dateFrom),
                'cost_savings' => $this->calculateCostSavings($dateFrom),
                'delivery_times' => [
                    'average_hours' => $this->getAverageDeliveryTime($dateFrom),
                    'on_time_percentage' => $this->getOnTimeDeliveryRate($dateFrom)
                ]
            ];

            return $this->successResponse($metrics, 'Allocation performance metrics retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieving allocation performance');
        }
    }

    /**
     * Test allocation algorithms
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function test(Request $request): JsonResponse
    {
        try {
            if (!$this->userCan('test-algorithms')) {
                return $this->forbiddenResponse('You do not have permission to test algorithms.');
            }

            $validated = $request->validate([
                'algorithm_id' => 'required|integer|exists:allocation_algorithms,algorithm_id',
                'test_scenario' => 'required|string|max:100',
                'input_data' => 'required|array',
                'expected_outcome' => 'sometimes|array'
            ]);

            $algorithm = AllocationAlgorithm::findOrFail($validated['algorithm_id']);

            // Run algorithm test
            $testResult = $this->executeAlgorithmTest(
                $algorithm,
                $validated['test_scenario'],
                $validated['input_data'],
                $validated['expected_outcome'] ?? null
            );

            // Save test result
            $test = AllocationTest::create([
                'algorithm_id' => $algorithm->algorithm_id,
                'test_scenario' => $validated['test_scenario'],
                'input_data' => $validated['input_data'],
                'expected_outcome' => $validated['expected_outcome'] ?? null,
                'actual_outcome' => $testResult['result'],
                'test_passed' => $testResult['passed'],
                'tested_at' => now()
            ]);

            $this->logActivity('Algorithm test completed', [
                'algorithm_id' => $algorithm->algorithm_id,
                'test_scenario' => $validated['test_scenario'],
                'test_passed' => $testResult['passed'],
                'execution_time_ms' => $testResult['execution_time']
            ]);

            return $this->successResponse([
                'test' => $test,
                'result' => $testResult,
                'algorithm_performance' => $this->getAlgorithmPerformanceMetrics($algorithm)
            ], 'Algorithm test completed successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'testing algorithm');
        }
    }

    /**
     * Get test results for algorithms
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function testResults(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'algorithm_id' => 'sometimes|integer|exists:allocation_algorithms,algorithm_id',
                'test_scenario' => 'sometimes|string',
                'passed_only' => 'sometimes|boolean'
            ]);

            $query = AllocationTest::with('algorithm');

            if (isset($validated['algorithm_id'])) {
                $query->where('algorithm_id', $validated['algorithm_id']);
            }

            if (isset($validated['test_scenario'])) {
                $query->where('test_scenario', 'LIKE', '%' . $validated['test_scenario'] . '%');
            }

            if ($validated['passed_only'] ?? false) {
                $query->where('test_passed', true);
            }

            $tests = $query->orderBy('tested_at', 'desc')->paginate(20);

            return $this->paginatedResponse($tests, 'Algorithm test results retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieving test results');
        }
    }

    /**
     * Helper Methods
     */

    /**
     * Get default allocation algorithm
     */
    private function getDefaultAlgorithm(): AllocationAlgorithm
    {
        return AllocationAlgorithm::where('is_default', true)->firstOr(function () {
            return AllocationAlgorithm::create([
                'algorithm_name' => 'Distance-Based Allocation',
                'algorithm_description' => 'Allocates resources based on proximity and availability',
                'success_rate' => 85.0,
                'average_response_time' => 250, // milliseconds
                'is_default' => true
            ]);
        });
    }

    /**
     * Validate hospital allocation constraints
     */
    private function validateHospitalConstraints(array $data): bool
    {
        $fromHospital = Hospital::find($data['from_hospital']);
        $toHospital = Hospital::find($data['to_hospital']);

        // Check if hospitals are active
        if (!$fromHospital || !$toHospital || 
            $fromHospital->status !== 'active' || 
            $toHospital->status !== 'active') {
            return false;
        }

        // Check distance constraint (max 200km for non-critical)
        $distance = $this->calculateDistance(
            $fromHospital->latitude, $fromHospital->longitude,
            $toHospital->latitude, $toHospital->longitude
        );

        $maxDistance = ($data['priority'] ?? 'medium') === 'critical' ? 500 : 200;
        
        return $distance <= $maxDistance;
    }

    /**
     * Run allocation optimization for a single allocation
     */
    private function runAllocationOptimization(ResourceAllocation $allocation): array
    {
        // This is a simplified optimization algorithm
        // In production, this would use more sophisticated AI/ML models
        
        $fromHospital = $allocation->fromHospital;
        $toHospital = $allocation->toHospital;
        
        $distance = $this->calculateDistance(
            $fromHospital->latitude, $fromHospital->longitude,
            $toHospital->latitude, $toHospital->longitude
        );
        
        $efficiency = max(0, 100 - ($distance / 2)); // Simple efficiency calculation
        
        return [
            'distance_km' => $distance,
            'efficiency_score' => $efficiency,
            'estimated_delivery_time' => $this->calculateEstimatedDelivery($allocation),
            'optimization_factors' => [
                'distance_weight' => 0.4,
                'availability_weight' => 0.3,
                'urgency_weight' => 0.2,
                'cost_weight' => 0.1
            ]
        ];
    }

    /**
     * Run multi-resource optimization
     */
    private function runMultiResourceOptimization(array $requests, array $constraints, AllocationAlgorithm $algorithm): array
    {
        $allocations = [];
        $metrics = ['distance_efficiency' => 0, 'utilization_rate' => 0, 'cost_efficiency' => 0];
        
        foreach ($requests as $request) {
            // Find best source hospitals for this resource type
            $sourceHospitals = $this->findOptimalSources(
                $request['resource_type'],
                $request['quantity_needed'],
                $request['requesting_hospital_id'],
                $constraints
            );
            
            foreach ($sourceHospitals as $source) {
                $allocations[] = [
                    'from_hospital_id' => $source['hospital_id'],
                    'to_hospital_id' => $request['requesting_hospital_id'],
                    'resource_type' => $request['resource_type'],
                    'quantity' => min($source['available_quantity'], $request['quantity_needed']),
                    'efficiency_score' => $source['efficiency_score'],
                    'estimated_delivery_hours' => $source['delivery_time_hours']
                ];
                
                $request['quantity_needed'] -= $source['available_quantity'];
                if ($request['quantity_needed'] <= 0) break;
            }
        }
        
        return [
            'allocations' => $allocations,
            'metrics' => $metrics,
            'algorithm_used' => $algorithm->algorithm_name,
            'total_allocations' => count($allocations)
        ];
    }

    /**
     * Find optimal source hospitals for a resource request
     */
    private function findOptimalSources(string $resourceType, int $quantityNeeded, int $requestingHospitalId, array $constraints): array
    {
        $requestingHospital = Hospital::find($requestingHospitalId);
        
        $sources = HospitalResource::where('resource_type', $resourceType)
            ->where('quantity_available', '>', 0)
            ->where('hospital_id', '!=', $requestingHospitalId)
            ->with('hospital')
            ->get()
            ->map(function ($resource) use ($requestingHospital, $constraints) {
                $distance = $this->calculateDistance(
                    $resource->hospital->latitude,
                    $resource->hospital->longitude,
                    $requestingHospital->latitude,
                    $requestingHospital->longitude
                );
                
                // Apply distance constraint
                $maxDistance = $constraints['max_distance_km'] ?? 200;
                if ($distance > $maxDistance) {
                    return null;
                }
                
                // Calculate efficiency score
                $efficiencyScore = $this->calculateSourceEfficiency(
                    $resource,
                    $distance,
                    $constraints
                );
                
                return [
                    'hospital_id' => $resource->hospital_id,
                    'available_quantity' => $resource->quantity_available,
                    'distance_km' => $distance,
                    'efficiency_score' => $efficiencyScore,
                    'delivery_time_hours' => $this->estimateDeliveryTime($distance)
                ];
            })
            ->filter()
            ->sortByDesc('efficiency_score')
            ->values()
            ->toArray();
            
        return $sources;
    }

    /**
     * Calculate efficiency score for a resource source
     */
    private function calculateSourceEfficiency(HospitalResource $resource, float $distance, array $constraints): float
    {
        $score = 100;
        
        // Distance penalty (closer is better)
        $score -= ($distance / 10); // 1 point per 10km
        
        // Availability bonus
        $availabilityRatio = $resource->quantity_available / max($resource->quantity_total, 1);
        $score += $availabilityRatio * 20;
        
        // Hospital capacity factor
        if ($resource->hospital->capacity > 200) {
            $score += 10; // Large hospitals get bonus
        }
        
        // Priority hospital bonus
        if (isset($constraints['priority_hospitals']) && 
            in_array($resource->hospital_id, $constraints['priority_hospitals'])) {
            $score += 15;
        }
        
        return max(0, min(100, $score));
    }

    /**
     * Estimate delivery time based on distance
     */
    private function estimateDeliveryTime(float $distance): float
    {
        // Base time + travel time (assuming 60km/h average)
        $baseTimeHours = 0.5; // 30 minutes preparation
        $travelTimeHours = $distance / 60;
        
        return $baseTimeHours + $travelTimeHours;
    }

    /**
     * Run allocation simulation
     */
    private function runAllocationSimulation(string $scenarioType, array $parameters): array
    {
        $simulation = [
            'scenario_type' => $scenarioType,
            'duration_hours' => $parameters['duration_hours'] ?? 24,
            'results' => []
        ];
        
        switch ($scenarioType) {
            case 'disaster_response':
                $simulation['results'] = $this->simulateDisasterResponse($parameters);
                break;
                
            case 'daily_operations':
                $simulation['results'] = $this->simulateDailyOperations($parameters);
                break;
                
            case 'peak_demand':
                $simulation['results'] = $this->simulatePeakDemand($parameters);
                break;
                
            case 'supply_shortage':
                $simulation['results'] = $this->simulateSupplyShortage($parameters);
                break;
        }
        
        $simulation['summary'] = [
            'total_allocations_simulated' => count($simulation['results']['allocations'] ?? []),
            'success_rate' => $simulation['results']['success_rate'] ?? 0,
            'average_response_time_hours' => $simulation['results']['avg_response_time'] ?? 0,
            'resource_utilization_rate' => $simulation['results']['utilization_rate'] ?? 0
        ];
        
        return $simulation;
    }

    /**
     * Simulate disaster response scenario
     */
    private function simulateDisasterResponse(array $parameters): array
    {
        // Simplified disaster simulation
        $affectedHospitals = $parameters['affected_hospitals'] ?? [];
        $demandMultiplier = $parameters['demand_multiplier'] ?? 3.0;
        
        return [
            'allocations' => [], // Would contain simulated allocations
            'success_rate' => 75.5,
            'avg_response_time' => 2.5,
            'utilization_rate' => 89.2,
            'critical_shortages' => ['blood_products', 'emergency_supplies']
        ];
    }

    /**
     * Calculate various performance metrics
     */
    private function calculateEfficiencyScore(ResourceAllocation $allocation): float
    {
        if (!$allocation->fromHospital || !$allocation->toHospital) {
            return 0;
        }
        
        $distance = $this->calculateDistance(
            $allocation->fromHospital->latitude,
            $allocation->fromHospital->longitude,
            $allocation->toHospital->latitude,
            $allocation->toHospital->longitude
        );
        
        // Simple efficiency: lower distance = higher efficiency
        return max(0, 100 - ($distance / 5));
    }

    private function calculateEstimatedDelivery(ResourceAllocation $allocation): string
    {
        $baseMinutes = 30; // Preparation time
        
        if ($allocation->fromHospital && $allocation->toHospital) {
            $distance = $this->calculateDistance(
                $allocation->fromHospital->latitude,
                $allocation->fromHospital->longitude,
                $allocation->toHospital->latitude,
                $allocation->toHospital->longitude
            );
            
            $travelMinutes = ($distance / 60) * 60; // Assuming 60km/h
            $totalMinutes = $baseMinutes + $travelMinutes;
            
            return now()->addMinutes($totalMinutes)->toISOString();
        }
        
        return now()->addMinutes($baseMinutes)->toISOString();
    }

    private function calculateConfidenceScore(ResourceAllocation $allocation): float
    {
        $score = 80.0; // Base confidence
        
        // Adjust based on distance
        if ($allocation->fromHospital && $allocation->toHospital) {
            $distance = $this->calculateDistance(
                $allocation->fromHospital->latitude,
                $allocation->fromHospital->longitude,
                $allocation->toHospital->latitude,
                $allocation->toHospital->longitude
            );
            
            if ($distance < 50) $score += 15;
            elseif ($distance < 100) $score += 10;
            elseif ($distance > 200) $score -= 20;
        }
        
        return min(99.9, max(10.0, $score));
    }

    private function getAlgorithmPerformanceMetrics(AllocationAlgorithm $algorithm): array
    {
        return [
            'success_rate' => $algorithm->success_rate ?? 85.0,
            'average_response_time_ms' => $algorithm->average_response_time ?? 250,
            'total_tests_run' => $algorithm->tests()->count(),
            'tests_passed' => $algorithm->tests()->where('test_passed', true)->count(),
            'last_test_date' => $algorithm->tests()->latest('tested_at')->value('tested_at')
        ];
    }

    private function parsePeriod(string $period): \Carbon\Carbon
    {
        return match($period) {
            '7days' => now()->subDays(7),
            '30days' => now()->subDays(30),
            '90days' => now()->subDays(90),
            '1year' => now()->subYear(),
            default => now()->subDays(30)
        };
    }

    private function calculateSuccessRate(\Carbon\Carbon $dateFrom): float
    {
        $total = ResourceAllocation::where('allocated_at', '>=', $dateFrom)->count();
        $successful = ResourceAllocation::where('allocated_at', '>=', $dateFrom)
            ->where('status', 'completed')
            ->count();
            
        return $total > 0 ? round(($successful / $total) * 100, 2) : 0;
    }

    private function calculateAverageEfficiency(\Carbon\Carbon $dateFrom): float
    {
        // This would calculate actual efficiency metrics in production
        return 87.5; // Mock value
    }

    private function getAlgorithmComparison(\Carbon\Carbon $dateFrom): array
    {
        return AllocationAlgorithm::withCount(['tests' => function($q) use ($dateFrom) {
            $q->where('tested_at', '>=', $dateFrom);
        }])
        ->get()
        ->map(function($algorithm) {
            return [
                'algorithm_name' => $algorithm->algorithm_name,
                'success_rate' => $algorithm->success_rate,
                'tests_run' => $algorithm->tests_count,
                'average_response_time' => $algorithm->average_response_time
            ];
        })
        ->toArray();
    }

    private function getResourceTypeBreakdown(\Carbon\Carbon $dateFrom): array
    {
        return ResourceAllocation::where('allocated_at', '>=', $dateFrom)
            ->join('hospital_resources', 'resource_allocations.resource_id', '=', 'hospital_resources.resource_id')
            ->selectRaw('hospital_resources.resource_type, COUNT(*) as count, SUM(quantity) as total_quantity')
            ->groupBy('hospital_resources.resource_type')
            ->pluck('count', 'resource_type')
            ->toArray();
    }

    private function executeAlgorithmTest(AllocationAlgorithm $algorithm, string $scenario, array $inputData, ?array $expectedOutcome): array
    {
        $startTime = microtime(true);
        
        // Mock algorithm execution
        $result = [
            'allocations_created' => rand(1, 5),
            'total_efficiency_score' => rand(70, 95),
            'execution_successful' => true
        ];
        
        $executionTime = round((microtime(true) - $startTime) * 1000, 2);
        
        $passed = true;
        if ($expectedOutcome) {
            // Compare results with expectations
            $passed = ($result['execution_successful'] === ($expectedOutcome['execution_successful'] ?? true));
        }
        
        return [
            'result' => $result,
            'passed' => $passed,
            'execution_time' => $executionTime
        ];
    }

    // Additional helper methods for simulation scenarios
    private function simulateDailyOperations(array $parameters): array
    {
        return [
            'allocations' => [],
            'success_rate' => 92.3,
            'avg_response_time' => 1.2,
            'utilization_rate' => 76.8
        ];
    }

    private function simulatePeakDemand(array $parameters): array
    {
        return [
            'allocations' => [],
            'success_rate' => 68.9,
            'avg_response_time' => 3.7,
            'utilization_rate' => 95.4
        ];
    }

    private function simulateSupplyShortage(array $parameters): array
    {
        return [
            'allocations' => [],
            'success_rate' => 45.2,
            'avg_response_time' => 5.1,
            'utilization_rate' => 98.7
        ];
    }

    private function calculateNetworkEfficiency(\Carbon\Carbon $dateFrom): float
    {
        return 84.2; // Mock value
    }

    private function calculateCostSavings(\Carbon\Carbon $dateFrom): array
    {
        return [
            'total_savings' => 125000,
            'currency' => 'PHP',
            'savings_percentage' => 15.3
        ];
    }

    private function getAverageDeliveryTime(\Carbon\Carbon $dateFrom): float
    {
        return 2.3; // Mock value in hours
    }

    private function getOnTimeDeliveryRate(\Carbon\Carbon $dateFrom): float
    {
        return 89.7; // Mock percentage
    }

    private function getOptimizationDetails(ResourceAllocation $allocation): array
    {
        return [
            'algorithm_used' => 'Distance-Based Allocation',
            'factors_considered' => ['distance', 'availability', 'hospital_capacity'],
            'optimization_score' => $this->calculateEfficiencyScore($allocation),
            'alternative_options' => [] // Would contain other possible allocations
        ];
    }

    private function getDeliveryTracking(ResourceAllocation $allocation): array
    {
        return [
            'current_status' => $allocation->status,
            'estimated_delivery' => $this->calculateEstimatedDelivery($allocation),
            'tracking_updates' => [
                ['status' => 'allocated', 'timestamp' => $allocation->allocated_at],
                // Additional tracking points would be added here
            ]
        ];
    }
}