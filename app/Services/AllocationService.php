<?php

namespace App\Services;

use App\Models\RequestEntry;
use App\Models\Hospital;
use App\Models\HospitalResource;
use App\Models\ResourceAllocation;
use App\Models\AllocationAlgorithm;
use App\Models\InventoryLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class AllocationService
{
    /**
     * Find optimal hospital for resource allocation
     * 
     * @param RequestEntry $request
     * @return Hospital|null
     */
    public function findOptimalHospital(RequestEntry $request)
    {
        try {
            // Get requesting hospital location for distance calculation
            $requestingHospital = Hospital::find($request->requesting_hospital_id);
            
            // Find hospitals with sufficient resources
            $availableHospitals = Hospital::whereHas('hospitalResources', function ($query) use ($request) {
                $query->where('resource_type', $request->resource_type)
                      ->where('current_quantity', '>=', $request->quantity_needed)
                      ->where('status', 'available');
            })
            ->where('id', '!=', $request->requesting_hospital_id)
            ->where('status', 'active')
            ->with('hospitalResources')
            ->get();

            if ($availableHospitals->isEmpty()) {
                Log::warning("No hospitals available for allocation", [
                    'request_id' => $request->id,
                    'resource_type' => $request->resource_type
                ]);
                return null;
            }

            // Score hospitals based on multiple factors
            $scoredHospitals = $availableHospitals->map(function ($hospital) use ($request, $requestingHospital) {
                $resource = $hospital->hospitalResources
                    ->where('resource_type', $request->resource_type)
                    ->first();

                // Calculate distance (simplified - in production use actual geo calculation)
                $distance = $this->calculateDistance(
                    $requestingHospital->latitude,
                    $requestingHospital->longitude,
                    $hospital->latitude,
                    $hospital->longitude
                );

                // Scoring factors
                $availabilityScore = ($resource->current_quantity / $request->quantity_needed) * 100;
                $distanceScore = (100 - min($distance, 100)); // Inverse distance
                $urgencyMultiplier = $request->urgency_level === 'critical' ? 1.5 : 1.0;

                $totalScore = ($availabilityScore * 0.6 + $distanceScore * 0.4) * $urgencyMultiplier;

                return [
                    'hospital' => $hospital,
                    'score' => $totalScore,
                    'distance' => $distance,
                    'available_quantity' => $resource->current_quantity
                ];
            });

            // Return hospital with highest score
            $optimal = $scoredHospitals->sortByDesc('score')->first();
            
            Log::info("Optimal hospital found", [
                'request_id' => $request->id,
                'hospital_id' => $optimal['hospital']->id,
                'score' => $optimal['score']
            ]);

            return $optimal['hospital'];

        } catch (Exception $e) {
            Log::error("Error finding optimal hospital: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Allocate resources to a request
     * 
     * @param RequestEntry $request
     * @param int $algorithmId
     * @return ResourceAllocation
     */
    public function allocateResources(RequestEntry $request, int $algorithmId = 1)
    {
        DB::beginTransaction();
        
        try {
            // Find optimal hospital
            $hospital = $this->findOptimalHospital($request);
            
            if (!$hospital) {
                throw new Exception("No suitable hospital found for allocation");
            }

            // Get hospital resource
            $hospitalResource = HospitalResource::where('hospital_id', $hospital->id)
                ->where('resource_type', $request->resource_type)
                ->lockForUpdate()
                ->first();

            if (!$hospitalResource || $hospitalResource->current_quantity < $request->quantity_needed) {
                throw new Exception("Insufficient resources at selected hospital");
            }

            // Create allocation record
            $allocation = ResourceAllocation::create([
                'request_entry_id' => $request->id,
                'allocated_hospital_id' => $hospital->id,
                'resource_type' => $request->resource_type,
                'quantity_allocated' => $request->quantity_needed,
                'allocation_status' => 'pending',
                'allocation_algorithm_id' => $algorithmId,
                'allocated_at' => now(),
                'notes' => "Allocated by system algorithm"
            ]);

            // Update hospital resource quantity
            $hospitalResource->decrement('current_quantity', $request->quantity_needed);

            // Log inventory change
            InventoryLog::create([
                'hospital_resource_id' => $hospitalResource->id,
                'hospital_id' => $hospital->id,
                'change_type' => 'allocation',
                'quantity_change' => -$request->quantity_needed,
                'reason' => "Allocated to request #{$request->id}",
                'performed_by' => auth()->id() ?? 1,
                'timestamp' => now()
            ]);

            // Update request status
            $request->update([
                'status' => 'allocated',
                'updated_at' => now()
            ]);

            DB::commit();

            Log::info("Resources allocated successfully", [
                'allocation_id' => $allocation->id,
                'request_id' => $request->id,
                'hospital_id' => $hospital->id
            ]);

            return $allocation;

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Resource allocation failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Check resource availability across all hospitals
     * 
     * @param string $resourceType
     * @param int $quantity
     * @return array
     */
    public function checkAvailability(string $resourceType, int $quantity)
    {
        try {
            $availableHospitals = Hospital::whereHas('hospitalResources', function ($query) use ($resourceType, $quantity) {
                $query->where('resource_type', $resourceType)
                      ->where('current_quantity', '>=', $quantity)
                      ->where('status', 'available');
            })
            ->with(['hospitalResources' => function ($query) use ($resourceType) {
                $query->where('resource_type', $resourceType);
            }])
            ->get();

            $totalAvailable = $availableHospitals->sum(function ($hospital) use ($resourceType) {
                return $hospital->hospitalResources
                    ->where('resource_type', $resourceType)
                    ->sum('current_quantity');
            });

            return [
                'resource_type' => $resourceType,
                'requested_quantity' => $quantity,
                'total_available' => $totalAvailable,
                'hospitals_count' => $availableHospitals->count(),
                'is_sufficient' => $totalAvailable >= $quantity,
                'hospitals' => $availableHospitals->map(function ($hospital) use ($resourceType) {
                    $resource = $hospital->hospitalResources
                        ->where('resource_type', $resourceType)
                        ->first();
                    
                    return [
                        'id' => $hospital->id,
                        'name' => $hospital->name,
                        'available_quantity' => $resource->current_quantity,
                        'location' => [
                            'latitude' => $hospital->latitude,
                            'longitude' => $hospital->longitude
                        ]
                    ];
                })
            ];

        } catch (Exception $e) {
            Log::error("Error checking availability: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Bulk allocate multiple resources
     * 
     * @param array $requestIds
     * @return array
     */
    public function bulkAllocate(array $requestIds)
    {
        $results = [
            'successful' => [],
            'failed' => []
        ];

        foreach ($requestIds as $requestId) {
            try {
                $request = RequestEntry::findOrFail($requestId);
                $allocation = $this->allocateResources($request);
                
                $results['successful'][] = [
                    'request_id' => $requestId,
                    'allocation_id' => $allocation->id
                ];
            } catch (Exception $e) {
                $results['failed'][] = [
                    'request_id' => $requestId,
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    /**
     * Calculate distance between two coordinates (simplified Haversine)
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
     * Get allocation statistics
     * 
     * @param array $filters
     * @return array
     */
    public function getAllocationStatistics(array $filters = [])
    {
        $query = ResourceAllocation::query();

        if (isset($filters['start_date'])) {
            $query->where('allocated_at', '>=', $filters['start_date']);
        }

        if (isset($filters['end_date'])) {
            $query->where('allocated_at', '<=', $filters['end_date']);
        }

        if (isset($filters['status'])) {
            $query->where('allocation_status', $filters['status']);
        }

        $allocations = $query->get();

        return [
            'total_allocations' => $allocations->count(),
            'by_status' => $allocations->groupBy('allocation_status')->map->count(),
            'by_resource_type' => $allocations->groupBy('resource_type')->map->count(),
            'total_quantity_allocated' => $allocations->sum('quantity_allocated'),
            'average_allocation_time' => $allocations->avg(function ($allocation) {
                return $allocation->allocated_at ? 
                    $allocation->allocated_at->diffInMinutes($allocation->created_at) : 0;
            })
        ];
    }
}