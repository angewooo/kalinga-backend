<?php

namespace App\Http\Controllers\API;

use App\Models\HospitalResource;
use App\Models\ResourceThreshold;
use App\Models\ResourceAllocation;
use App\Models\InventoryLog;
use App\Models\Hospital;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Resource Management Controller for Project Kalinga
 * Handles hospital resource management, inventory, and transfers
 */
class ResourceController extends BaseApiController
{
    /**
     * Get the model class for this controller
     */
    protected function getModel(): string
    {
        return HospitalResource::class;
    }

    /**
     * Get validation rules for resource operations
     */
    protected function getValidationRules(): array
    {
        return [
            'hospital_id' => 'required|integer|exists:hospitals,hospital_id',
            'resource_type' => 'required|string|max:50|in:medical_supplies,medications,equipment,blood_products,oxygen,protective_gear,emergency_supplies',
            'quantity_total' => 'required|integer|min:0',
            'quantity_available' => 'required|integer|min:0|lte:quantity_total',
            'unit' => 'required|string|max:20|in:pieces,boxes,liters,kg,units,doses,bottles,packs',
            'cost_per_unit' => 'nullable|numeric|min:0',
        ];
    }

    /**
     * Get searchable fields
     */
    protected function getSearchableFields(): array
    {
        return ['resource_type', 'hospital.name', 'unit'];
    }

    /**
     * Get default relationships to load
     */
    protected function getDefaultRelations(): array
    {
        return ['hospital', 'thresholds', 'batches'];
    }

    /**
     * Display a listing of resources
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Validate query parameters
            $request->validate($this->commonRules + [
                'hospital_id' => 'integer|exists:hospitals,hospital_id',
                'resource_type' => 'string|in:medical_supplies,medications,equipment,blood_products,oxygen,protective_gear,emergency_supplies',
                'status' => 'string|in:normal,low_stock,out_of_stock,overstock,expiring',
                'min_quantity' => 'integer|min:0',
                'max_quantity' => 'integer|min:0',
                'cost_range_min' => 'numeric|min:0',
                'cost_range_max' => 'numeric|min:0'
            ]);

            $query = HospitalResource::with($this->getDefaultRelations());

            // Apply standard filters
            $query = $this->applyFilters($query, $request, $this->getSearchableFields());

            // Hospital filter
            if ($hospitalId = $request->get('hospital_id')) {
                $query->where('hospital_id', $hospitalId);
            }

            // Resource type filter
            if ($resourceType = $request->get('resource_type')) {
                $query->where('resource_type', $resourceType);
            }

            // Quantity filters
            if ($minQuantity = $request->get('min_quantity')) {
                $query->where('quantity_available', '>=', $minQuantity);
            }
            if ($maxQuantity = $request->get('max_quantity')) {
                $query->where('quantity_available', '<=', $maxQuantity);
            }

            // Cost filters
            if ($minCost = $request->get('cost_range_min')) {
                $query->where('cost_per_unit', '>=', $minCost);
            }
            if ($maxCost = $request->get('cost_range_max')) {
                $query->where('cost_per_unit', '<=', $maxCost);
            }

            // Status-based filtering
            if ($status = $request->get('status')) {
                if ($status === 'out_of_stock') {
                    $query->where('quantity_available', 0);
                } elseif ($status === 'low_stock') {
                    $query->whereHas('thresholds', function($q) {
                        $q->whereRaw('hospital_resources.quantity_available <= resource_thresholds.min_level');
                    });
                } elseif ($status === 'overstock') {
                    $query->whereHas('thresholds', function($q) {
                        $q->whereRaw('hospital_resources.quantity_available >= resource_thresholds.max_level');
                    });
                }
            }

            // Pagination
            $params = $this->getPaginationParams($request);
            $resources = $query->paginate($params['per_page']);

            // Add calculated fields
            $resources->getCollection()->transform(function ($resource) {
                $resource->status = $this->getResourceStatus($resource);
                $resource->utilization_rate = $this->calculateUtilizationRate($resource);
                $resource->days_until_expiry = $this->calculateDaysUntilExpiry($resource);
                $resource->value_total = $resource->quantity_total * ($resource->cost_per_unit ?? 0);
                $resource->value_available = $resource->quantity_available * ($resource->cost_per_unit ?? 0);
                return $resource;
            });

            $this->logActivity('Resources listed', [
                'total' => $resources->total(),
                'filters' => $request->only(['hospital_id', 'resource_type', 'status'])
            ]);

            return $this->paginatedResponse($resources, 'Resources retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'listing resources');
        }
    }

    /**
     * Store a newly created resource
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        try {
            if (!$this->userCan('create-resources')) {
                return $this->forbiddenResponse('You do not have permission to create resources.');
            }

            $validated = $request->validate($this->getValidationRules() + [
                'min_threshold' => 'nullable|integer|min:0',
                'max_threshold' => 'nullable|integer|gte:min_threshold',
                'expiry_date' => 'nullable|date|after:today',
                'supplier_id' => 'nullable|integer|exists:suppliers,supplier_id',
                'batch_number' => 'nullable|string|max:50'
            ]);

            DB::beginTransaction();

            // Calculate total cost
            $totalCost = ($validated['cost_per_unit'] ?? 0) * $validated['quantity_total'];

            // Create resource
            $resource = HospitalResource::create([
                'hospital_id' => $validated['hospital_id'],
                'resource_type' => $validated['resource_type'],
                'quantity_total' => $validated['quantity_total'],
                'quantity_available' => $validated['quantity_available'],
                'unit' => $validated['unit'],
                'cost_per_unit' => $validated['cost_per_unit'],
                'total_cost' => $totalCost,
                'last_updated' => now()
            ]);

            // Create threshold if provided
            if (isset($validated['min_threshold'])) {
                ResourceThreshold::create([
                    'resource_id' => $resource->resource_id,
                    'min_level' => $validated['min_threshold'],
                    'max_level' => $validated['max_threshold'] ?? ($validated['min_threshold'] * 3),
                    'alert_triggered' => false
                ]);
            }

            // Create initial inventory log
            InventoryLog::create([
                'resource_id' => $resource->resource_id,
                'action_type' => 'initial_stock',
                'quantity' => $validated['quantity_total'],
                'notes' => 'Initial resource creation',
                'timestamp' => now()
            ]);

            DB::commit();

            $resource->load($this->getDefaultRelations());

            $this->logActivity('Resource created', [
                'resource_id' => $resource->resource_id,
                'hospital_id' => $resource->hospital_id,
                'resource_type' => $resource->resource_type,
                'quantity_total' => $resource->quantity_total
            ]);

            return $this->createdResponse($resource, 'Resource created successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->handleException($e, 'creating resource');
        }
    }

    /**
     * Display the specified resource
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            $resource = HospitalResource::with([
                'hospital',
                'thresholds',
                'batches'
            ])->findOrFail($id);

            // Add calculated metrics
            $resource->status = $this->getResourceStatus($resource);
            $resource->utilization_rate = $this->calculateUtilizationRate($resource);
            $resource->days_until_expiry = $this->calculateDaysUntilExpiry($resource);
            $resource->consumption_trend = $this->getConsumptionTrend($resource);
            $resource->reorder_recommendation = $this->getReorderRecommendation($resource);

            $this->logActivity('Resource viewed', ['resource_id' => $resource->resource_id]);

            return $this->successResponse($resource, 'Resource retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieving resource');
        }
    }

    /**
     * Update the specified resource
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            if (!$this->userCan('update-resources')) {
                return $this->forbiddenResponse('You do not have permission to update resources.');
            }

            $resource = HospitalResource::findOrFail($id);

            // Make fields optional for updates
            $rules = $this->getValidationRules();
            foreach ($rules as $key => $rule) {
                $rules[$key] = str_replace('required|', 'sometimes|', $rule);
            }

            $validated = $request->validate($rules + [
                'adjustment_reason' => 'sometimes|string|max:255',
                'notes' => 'sometimes|string|max:500'
            ]);

            DB::beginTransaction();

            $oldQuantityAvailable = $resource->quantity_available;

            // Update total cost if cost_per_unit or quantity_total changed
            if (isset($validated['cost_per_unit']) || isset($validated['quantity_total'])) {
                $costPerUnit = $validated['cost_per_unit'] ?? $resource->cost_per_unit;
                $quantityTotal = $validated['quantity_total'] ?? $resource->quantity_total;
                $validated['total_cost'] = $costPerUnit * $quantityTotal;
            }

            $validated['last_updated'] = now();

            $resource->update($validated);

            // Log inventory change if quantity changed
            if (isset($validated['quantity_available']) && $validated['quantity_available'] !== $oldQuantityAvailable) {
                $quantityDiff = $validated['quantity_available'] - $oldQuantityAvailable;
                $actionType = $quantityDiff > 0 ? 'adjustment_increase' : 'adjustment_decrease';

                InventoryLog::create([
                    'resource_id' => $resource->resource_id,
                    'action_type' => $actionType,
                    'quantity' => abs($quantityDiff),
                    'notes' => $validated['adjustment_reason'] ?? 'Manual adjustment',
                    'timestamp' => now()
                ]);
            }

            DB::commit();

            $resource->load($this->getDefaultRelations());

            $this->logActivity('Resource updated', [
                'resource_id' => $resource->resource_id,
                'updated_fields' => array_keys($validated),
                'quantity_changed' => isset($validated['quantity_available'])
            ]);

            return $this->updatedResponse($resource, 'Resource updated successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->handleException($e, 'updating resource');
        }
    }

    /**
     * Remove the specified resource
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            if (!$this->userCan('delete-resources')) {
                return $this->forbiddenResponse('You do not have permission to delete resources.');
            }

            $resource = HospitalResource::findOrFail($id);

            // Check for pending allocations
            if ($this->hasPendingAllocations($resource)) {
                return $this->errorResponse(
                    'Resource has pending allocations and cannot be deleted.',
                    409
                );
            }

            $resource->delete();

            $this->logActivity('Resource deleted', [
                'resource_id' => $resource->resource_id,
                'hospital_id' => $resource->hospital_id,
                'resource_type' => $resource->resource_type
            ]);

            return $this->deletedResponse('Resource deleted successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'deleting resource');
        }
    }

    /**
     * Get resource summary across all hospitals
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function summary(Request $request): JsonResponse
    {
        try {
            $summary = [
                'total_resources' => HospitalResource::count(),
                'total_value' => HospitalResource::sum(DB::raw('quantity_available * COALESCE(cost_per_unit, 0)')),
                'by_type' => HospitalResource::selectRaw('resource_type, COUNT(*) as count, SUM(quantity_available) as total_quantity')
                    ->groupBy('resource_type')
                    ->get(),
                'status_breakdown' => [
                    'normal' => $this->getResourceCountByStatus('normal'),
                    'low_stock' => $this->getResourceCountByStatus('low_stock'),
                    'out_of_stock' => $this->getResourceCountByStatus('out_of_stock'),
                    'overstock' => $this->getResourceCountByStatus('overstock')
                ],
                'top_hospitals_by_resources' => Hospital::withCount('resources')
                    ->orderBy('resources_count', 'desc')
                    ->limit(10)
                    ->get(['hospital_id', 'name', 'resources_count'])
            ];

            return $this->successResponse($summary, 'Resource summary retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieving resource summary');
        }
    }

    /**
     * Get low stock resources
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function lowStock(Request $request): JsonResponse
    {
        try {
            $query = HospitalResource::with($this->getDefaultRelations())
                ->where(function($q) {
                    $q->whereHas('thresholds', function($subQ) {
                        $subQ->whereRaw('hospital_resources.quantity_available <= resource_thresholds.min_level');
                    })
                    ->orWhere('quantity_available', 0);
                });

            // Optional hospital filter
            if ($hospitalId = $request->get('hospital_id')) {
                $query->where('hospital_id', $hospitalId);
            }

            $lowStockResources = $query->orderBy('quantity_available')
                ->paginate(20);

            // Add urgency score
            $lowStockResources->getCollection()->transform(function ($resource) {
                $resource->urgency_score = $this->calculateUrgencyScore($resource);
                $resource->recommended_order_quantity = $this->calculateReorderQuantity($resource);
                return $resource;
            });

            return $this->paginatedResponse($lowStockResources, 'Low stock resources retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieving low stock resources');
        }
    }

    /**
     * Get expiring resources
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function expiring(Request $request): JsonResponse
    {
        try {
            $daysThreshold = $request->get('days_threshold', 30);

            $expiringResources = HospitalResource::with(['hospital', 'batches' => function($q) use ($daysThreshold) {
                $q->where('expiry_date', '<=', now()->addDays($daysThreshold))
                  ->where('expiry_date', '>', now())
                  ->orderBy('expiry_date');
            }])
            ->whereHas('batches', function($q) use ($daysThreshold) {
                $q->where('expiry_date', '<=', now()->addDays($daysThreshold))
                  ->where('expiry_date', '>', now());
            })
            ->paginate(20);

            return $this->paginatedResponse($expiringResources, 'Expiring resources retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieving expiring resources');
        }
    }

    /**
     * Get resource utilization statistics
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function utilization(Request $request): JsonResponse
    {
        try {
            $period = $request->get('period', '30days');
            $dateFrom = $this->parsePeriod($period);

            $utilization = [
                'overall_utilization' => $this->calculateOverallUtilization(),
                'by_resource_type' => $this->getUtilizationByType(),
                'by_hospital' => $this->getUtilizationByHospital(),
                'trending_up' => $this->getTrendingResources('up'),
                'trending_down' => $this->getTrendingResources('down'),
                'period' => $period
            ];

            return $this->successResponse($utilization, 'Resource utilization retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieving resource utilization');
        }
    }

    /**
     * Transfer resources between hospitals
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function transfer(Request $request): JsonResponse
    {
        try {
            if (!$this->userCan('transfer-resources')) {
                return $this->forbiddenResponse('You do not have permission to transfer resources.');
            }

            $validated = $request->validate([
                'from_hospital_id' => 'required|integer|exists:hospitals,hospital_id',
                'to_hospital_id' => 'required|integer|exists:hospitals,hospital_id|different:from_hospital_id',
                'resource_type' => 'required|string',
                'quantity' => 'required|integer|min:1',
                'reason' => 'required|string|max:255',
                'priority' => 'sometimes|string|in:low,medium,high,critical',
                'notes' => 'nullable|string|max:500'
            ]);

            // Find source resource
            $sourceResource = HospitalResource::where('hospital_id', $validated['from_hospital_id'])
                ->where('resource_type', $validated['resource_type'])
                ->where('quantity_available', '>=', $validated['quantity'])
                ->first();

            if (!$sourceResource) {
                return $this->errorResponse('Insufficient resources available at source hospital.', 400);
            }

            DB::beginTransaction();

            // Create allocation record
            $allocation = ResourceAllocation::create([
                'resource_id' => $sourceResource->resource_id,
                'from_hospital' => $validated['from_hospital_id'],
                'to_hospital' => $validated['to_hospital_id'],
                'quantity' => $validated['quantity'],
                'reason' => $validated['reason'],
                'status' => 'pending'
            ]);

            // Reduce source hospital quantity
            $sourceResource->decrement('quantity_available', $validated['quantity']);

            // Find or create destination resource
            $destResource = HospitalResource::firstOrCreate(
                [
                    'hospital_id' => $validated['to_hospital_id'],
                    'resource_type' => $validated['resource_type']
                ],
                [
                    'quantity_total' => $validated['quantity'],
                    'quantity_available' => $validated['quantity'],
                    'unit' => $sourceResource->unit,
                    'cost_per_unit' => $sourceResource->cost_per_unit,
                    'total_cost' => $sourceResource->cost_per_unit * $validated['quantity']
                ]
            );

            if ($destResource->wasRecentlyCreated === false) {
                $destResource->increment('quantity_available', $validated['quantity']);
                $destResource->increment('quantity_total', $validated['quantity']);
            }

            // Log inventory changes
            InventoryLog::create([
                'resource_id' => $sourceResource->resource_id,
                'action_type' => 'transfer_out',
                'quantity' => $validated['quantity'],
                'notes' => "Transfer to Hospital ID {$validated['to_hospital_id']}: {$validated['reason']}"
            ]);

            InventoryLog::create([
                'resource_id' => $destResource->resource_id,
                'action_type' => 'transfer_in',
                'quantity' => $validated['quantity'],
                'notes' => "Transfer from Hospital ID {$validated['from_hospital_id']}: {$validated['reason']}"
            ]);

            // Update allocation status
            $allocation->update(['status' => 'completed']);

            DB::commit();

            $this->logActivity('Resource transfer completed', [
                'allocation_id' => $allocation->allocation_id,
                'from_hospital' => $validated['from_hospital_id'],
                'to_hospital' => $validated['to_hospital_id'],
                'resource_type' => $validated['resource_type'],
                'quantity' => $validated['quantity']
            ]);

            return $this->successResponse([
                'allocation' => $allocation,
                'source_resource' => $sourceResource->fresh(),
                'destination_resource' => $destResource->fresh()
            ], 'Resource transfer completed successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->handleException($e, 'transferring resources');
        }
    }

    /**
     * Get resource thresholds
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function thresholds(Request $request): JsonResponse
    {
        try {
            $query = ResourceThreshold::with(['resource.hospital']);

            if ($hospitalId = $request->get('hospital_id')) {
                $query->whereHas('resource', function($q) use ($hospitalId) {
                    $q->where('hospital_id', $hospitalId);
                });
            }

            if ($alertTriggered = $request->get('alert_triggered')) {
                $query->where('alert_triggered', $alertTriggered === 'true');
            }

            $thresholds = $query->paginate(20);

            return $this->paginatedResponse($thresholds, 'Resource thresholds retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieving resource thresholds');
        }
    }

    /**
     * Update resource threshold
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function updateThreshold(Request $request, int $id): JsonResponse
    {
        try {
            if (!$this->userCan('update-thresholds')) {
                return $this->forbiddenResponse('You do not have permission to update thresholds.');
            }

            $threshold = ResourceThreshold::findOrFail($id);

            $validated = $request->validate([
                'min_level' => 'required|integer|min:0',
                'max_level' => 'required|integer|gte:min_level',
                'alert_triggered' => 'sometimes|boolean'
            ]);

            $threshold->update($validated);

            return $this->updatedResponse($threshold, 'Threshold updated successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'updating threshold');
        }
    }

    /**
     * Bulk update resources
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function bulkUpdate(Request $request): JsonResponse
    {
        try {
            if (!$this->userCan('bulk-update-resources')) {
                return $this->forbiddenResponse('You do not have permission to bulk update resources.');
            }

            $validated = $request->validate([
                'updates' => 'required|array|min:1|max:100',
                'updates.*.resource_id' => 'required|integer|exists:hospital_resources,resource_id',
                'updates.*.data' => 'required|array',
                'updates.*.data.quantity_available' => 'sometimes|integer|min:0',
                'updates.*.data.cost_per_unit' => 'sometimes|numeric|min:0',
                'reason' => 'required|string|max:255'
            ]);

            DB::beginTransaction();

            $results = [];
            foreach ($validated['updates'] as $update) {
                try {
                    $resource = HospitalResource::findOrFail($update['resource_id']);
                    $oldQuantity = $resource->quantity_available;

                    $resource->update($update['data']);

                    // Log quantity changes
                    if (isset($update['data']['quantity_available']) && $update['data']['quantity_available'] !== $oldQuantity) {
                        $quantityDiff = $update['data']['quantity_available'] - $oldQuantity;
                        $actionType = $quantityDiff > 0 ? 'bulk_increase' : 'bulk_decrease';

                        InventoryLog::create([
                            'resource_id' => $resource->resource_id,
                            'action_type' => $actionType,
                            'quantity' => abs($quantityDiff),
                            'notes' => "Bulk update: {$validated['reason']}"
                        ]);
                    }

                    $results[] = [
                        'resource_id' => $resource->resource_id,
                        'status' => 'updated',
                        'updated_fields' => array_keys($update['data'])
                    ];
                } catch (\Exception $e) {
                    $results[] = [
                        'resource_id' => $update['resource_id'],
                        'status' => 'failed',
                        'error' => $e->getMessage()
                    ];
                }
            }

            DB::commit();

            $successful = collect($results)->where('status', 'updated')->count();
            $failed = collect($results)->where('status', 'failed')->count();

            $this->logActivity('Bulk resource update', [
                'total_updates' => count($validated['updates']),
                'successful' => $successful,
                'failed' => $failed,
                'reason' => $validated['reason']
            ]);

            return $this->successResponse([
                'results' => $results,
                'summary' => [
                    'total' => count($validated['updates']),
                    'successful' => $successful,
                    'failed' => $failed
                ]
            ], "Bulk update completed. {$successful} successful, {$failed} failed.");

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->handleException($e, 'bulk updating resources');
        }
    }

    /**
     * Helper Methods
     */

    /**
     * Get resource status based on thresholds and availability
     */
    private function getResourceStatus(HospitalResource $resource): string
    {
        if ($resource->quantity_available <= 0) {
            return 'out_of_stock';
        }

        $threshold = $resource->thresholds->first();
        if (!$threshold) {
            return 'normal';
        }

        if ($resource->quantity_available <= $threshold->min_level) {
            return 'low_stock';
        }

        if ($resource->quantity_available >= $threshold->max_level) {
            return 'overstock';
        }

        return 'normal';
    }

    /**
     * Calculate resource utilization rate
     */
    private function calculateUtilizationRate(HospitalResource $resource): float
    {
        if ($resource->quantity_total <= 0) return 0;

        $used = $resource->quantity_total - $resource->quantity_available;
        return round(($used / $resource->quantity_total) * 100, 2);
    }

    /**
     * Calculate days until expiry
     */
    private function calculateDaysUntilExpiry(HospitalResource $resource): ?int
    {
        $nearestExpiry = $resource->batches
            ->where('expiry_date', '>', now())
            ->sortBy('expiry_date')
            ->first();

        return $nearestExpiry ? now()->diffInDays($nearestExpiry->expiry_date) : null;
    }

    /**
     * Get consumption trend
     */
    private function getConsumptionTrend(HospitalResource $resource): array
    {
        // Mock data - in production this would analyze actual usage patterns
        return [
            'daily_average' => rand(5, 50),
            'trend' => collect(['increasing', 'decreasing', 'stable'])->random(),
            'last_7_days' => array_map(fn() => rand(0, 100), range(1, 7))
        ];
    }

    /**
     * Get reorder recommendation
     */
    private function getReorderRecommendation(HospitalResource $resource): array
    {
        $threshold = $resource->thresholds->first();
        
        if (!$threshold) {
            return ['recommended' => false, 'reason' => 'No threshold set'];
        }
        
        if ($resource->quantity_available <= $threshold->min_level) {
            return [
                'recommended' => true,
                'urgency' => 'high',
                'reason' => 'Below minimum threshold',
                'suggested_quantity' => $threshold->max_level - $resource->quantity_available
            ];
        }
        
        return ['recommended' => false, 'reason' => 'Stock levels adequate'];
    }

    /**
     * Calculate urgency score for low stock items
     */
    private function calculateUrgencyScore(HospitalResource $resource): int
    {
        $score = 0;
        
        // Base score on stock level
        if ($resource->quantity_available == 0) $score += 50;
        elseif ($resource->quantity_available <= 5) $score += 30;
        elseif ($resource->quantity_available <= 10) $score += 20;
        
       // Add score based on hospital capacity
        if ($resource->hospital && $resource->hospital->capacity > 200) {
            $score += 10; // Large hospitals get priority
        }
        
        return min($score, 100);
    }

    /**
     * Calculate recommended reorder quantity
     */
    private function calculateReorderQuantity(HospitalResource $resource): int
    {
        $threshold = $resource->thresholds->first();
        
        if (!$threshold) {
            return 100; // Default quantity if no threshold
        }
        
        // Simple calculation: order enough to reach max level
        return max($threshold->max_level - $resource->quantity_available, 0);
    }

    /**
     * Get resource count by status
     */
    private function getResourceCountByStatus(string $status): int
    {
        $query = HospitalResource::query();
        
        switch ($status) {
            case 'low_stock':
                return $query->whereHas('thresholds', function($q) {
                    $q->whereRaw('hospital_resources.quantity_available <= resource_thresholds.min_level');
                })->count();
                
            case 'out_of_stock':
                return $query->where('quantity_available', 0)->count();
                
            case 'overstock':
                return $query->whereHas('thresholds', function($q) {
                    $q->whereRaw('hospital_resources.quantity_available >= resource_thresholds.max_level');
                })->count();
                
            default: // normal
                return $query->whereHas('thresholds', function($q) {
                    $q->whereRaw('hospital_resources.quantity_available > resource_thresholds.min_level')
                      ->whereRaw('hospital_resources.quantity_available < resource_thresholds.max_level');
                })->count();
        }
    }

    /**
     * Check if resource has pending allocations
     */
    private function hasPendingAllocations(HospitalResource $resource): bool
    {
        return ResourceAllocation::where('resource_id', $resource->resource_id)
            ->where('status', 'pending')
            ->exists();
    }

    /**
     * Parse period string to Carbon date
     */
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

    /**
     * Calculate overall utilization rate
     */
    private function calculateOverallUtilization(): float
    {
        $resources = HospitalResource::all();
        if ($resources->count() === 0) return 0;

        $totalUtilization = $resources->sum(function($resource) {
            return $this->calculateUtilizationRate($resource);
        });

        return round($totalUtilization / $resources->count(), 2);
    }

    /**
     * Get utilization by resource type
     */
    private function getUtilizationByType(): array
    {
        return HospitalResource::selectRaw('
            resource_type, 
            AVG((quantity_total - quantity_available) * 100.0 / NULLIF(quantity_total, 0)) as avg_utilization,
            COUNT(*) as resource_count
        ')
        ->groupBy('resource_type')
        ->get()
        ->mapWithKeys(function($item) {
            return [$item->resource_type => [
                'utilization_percentage' => round($item->avg_utilization ?? 0, 2),
                'resource_count' => $item->resource_count
            ]];
        })
        ->toArray();
    }

    /**
     * Get utilization by hospital
     */
    private function getUtilizationByHospital(): array
    {
        return Hospital::with('resources')
            ->get()
            ->map(function($hospital) {
                $utilization = $hospital->resources->avg(function($resource) {
                    return $this->calculateUtilizationRate($resource);
                });
                
                return [
                    'hospital_id' => $hospital->hospital_id,
                    'hospital_name' => $hospital->name,
                    'utilization_percentage' => round($utilization ?? 0, 2),
                    'resource_count' => $hospital->resources->count()
                ];
            })
            ->sortByDesc('utilization_percentage')
            ->values()
            ->toArray();
    }

    /**
     * Get trending resources
     */
    private function getTrendingResources(string $direction): array
    {
        // Mock implementation - in production this would analyze actual trends
        return HospitalResource::with('hospital')
            ->limit(10)
            ->get()
            ->map(function($resource) use ($direction) {
                return [
                    'resource_id' => $resource->resource_id,
                    'resource_type' => $resource->resource_type,
                    'hospital_name' => $resource->hospital->name,
                    'current_quantity' => $resource->quantity_available,
                    'trend_percentage' => $direction === 'up' ? rand(5, 25) : -rand(5, 25)
                ];
            })
            ->toArray();
    }
}