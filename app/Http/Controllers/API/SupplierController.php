<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\SupplyOrder;
use App\Models\ResourceBatch;
use App\Models\HospitalResource;
use App\Http\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SupplierController extends Controller
{
    use ApiResponseTrait;

    public function __construct()
     {
         // Apply middleware for different operations
         $this->middleware('permission:view-suppliers')->only(['index', 'show']);
         $this->middleware('permission:create-suppliers')->only(['store']);
         $this->middleware('permission:update-suppliers')->only(['update']);
         $this->middleware('permission:delete-suppliers')->only(['destroy']);
     }

    /**
     * Get supplier by ID (fixes route model binding issue)
     */
    private function getSupplier($id)
    {
        return Supplier::where('supplier_id', $id)->firstOrFail();
    }

    /**
     * Display a listing of suppliers with filtering and search
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = $request->get('per_page', 15);
            $search = $request->get('search');

            $query = Supplier::query();

            // Search functionality
            if ($search) {
                $query->where('name', 'ILIKE', "%{$search}%");
            }

            $suppliers = $query->orderBy('name')->paginate($perPage);

            return $this->successResponse($suppliers, 'Suppliers retrieved successfully');

        } catch (\Exception $e) {
            Log::error('Error retrieving suppliers: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve suppliers', 500);
        }
    }

    /**
     * Store a newly created supplier
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'name' => 'required|string|max:100',
                'contact_info' => 'nullable|array',
                'contact_info.email' => 'nullable|email',
                'contact_info.phone' => 'nullable|string|max:20',
                'contact_info.person' => 'nullable|string|max:100',
                'address' => 'nullable|string'
            ]);

            DB::beginTransaction();

            $supplierData = [
                'name' => $request->name,
                'contact_info' => $request->contact_info,
                'address' => $request->address
            ];

            $supplier = Supplier::create($supplierData);

            Log::info("Supplier created: {$supplier->name} by user " . auth()->id());

            DB::commit();

            return $this->successResponse($supplier, 'Supplier created successfully', 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating supplier: ' . $e->getMessage());
            return $this->errorResponse('Failed to create supplier', 500);
        }
    }

    /**
     * Display the specified supplier with detailed information
     */
    public function show($id): JsonResponse
    {
        try {
            $supplier = $this->getSupplier($id);
            
            // Get supplier statistics
            $supplier->statistics = [
                'total_orders' => 0,
                'active_orders' => 0,
                'completed_orders' => 0,
                'created_date' => $supplier->created_at->format('Y-m-d'),
                'last_updated' => $supplier->updated_at->format('Y-m-d H:i:s')
            ];

            return $this->successResponse($supplier, 'Supplier details retrieved successfully');

        } catch (\Exception $e) {
            Log::error('Error retrieving supplier details: ' . $e->getMessage());
            return $this->errorResponse('Supplier not found', 404);
        }
    }

    /**
     * Update the specified supplier
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $supplier = $this->getSupplier($id);
            
            $request->validate([
                'name' => 'sometimes|string|max:100',
                'contact_info' => 'nullable|array',
                'contact_info.email' => 'nullable|email',
                'contact_info.phone' => 'nullable|string|max:20',
                'contact_info.person' => 'nullable|string|max:100',
                'address' => 'nullable|string'
            ]);

            DB::beginTransaction();

            $updateData = [];
            
            if ($request->has('name')) {
                $updateData['name'] = $request->name;
            }
            
            if ($request->has('contact_info')) {
                $updateData['contact_info'] = $request->contact_info;
            }
            
            if ($request->has('address')) {
                $updateData['address'] = $request->address;
            }

            $supplier->update($updateData);

            Log::info("Supplier updated: {$supplier->name} by user " . auth()->id());

            DB::commit();

            return $this->successResponse($supplier, 'Supplier updated successfully');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating supplier: ' . $e->getMessage());
            return $this->errorResponse('Failed to update supplier', 500);
        }
    }

    /**
     * Remove the specified supplier
     */
    public function destroy($id): JsonResponse
    {
        try {
            $supplier = $this->getSupplier($id);
            
            DB::beginTransaction();

            $supplierName = $supplier->name;
            $supplier->delete();

            Log::info("Supplier deleted: {$supplierName} by user " . auth()->id());

            DB::commit();

            return $this->successResponse(null, 'Supplier deleted successfully');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting supplier: ' . $e->getMessage());
            return $this->errorResponse('Failed to delete supplier', 500);
        }
    }

    /**
     * Search suppliers by name or contact info
     */
    public function search(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'query' => 'required|string|min:2'
            ]);

            $query = $request->get('query');
            
            $suppliers = Supplier::where('name', 'ILIKE', "%{$query}%")
                ->orWhere('contact_info', 'ILIKE', "%{$query}%")
                ->orWhere('address', 'ILIKE', "%{$query}%")
                ->limit(10)
                ->get();

            return $this->successResponse($suppliers, 'Suppliers search completed');

        } catch (\Exception $e) {
            Log::error('Error searching suppliers: ' . $e->getMessage());
            return $this->errorResponse('Failed to search suppliers', 500);
        }
    }

    /**
     * Get supplier statistics
     */
    public function getStatistics(): JsonResponse
    {
        try {
            $statistics = [
                'total_suppliers' => Supplier::count(),
                'suppliers_with_contact' => Supplier::whereNotNull('contact_info')->count(),
                'suppliers_with_address' => Supplier::whereNotNull('address')->count(),
                'recent_additions' => Supplier::where('created_at', '>=', Carbon::now()->subDays(30))->count(),
                'top_suppliers_by_name' => Supplier::orderBy('name')->limit(5)->pluck('name')
            ];

            return $this->successResponse($statistics, 'Supplier statistics retrieved successfully');

        } catch (\Exception $e) {
            Log::error('Error retrieving supplier statistics: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve supplier statistics', 500);
        }
    }

    /**
     * Get supplier performance metrics (placeholder for future implementation)
     */
    public function getPerformance($id, Request $request): JsonResponse
    {
        try {
            $supplier = $this->getSupplier($id);
            $period = $request->get('period', '6months');

            $performance = [
                'supplier' => $supplier,
                'period' => $period,
                'metrics' => [
                    'total_orders' => 0,
                    'completed_orders' => 0,
                    'cancelled_orders' => 0,
                    'on_time_delivery_rate' => 0,
                    'quality_rating' => 0,
                    'response_time_hours' => 0
                ],
                'message' => 'Performance tracking will be available when supply order system is implemented'
            ];

            return $this->successResponse($performance, 'Supplier performance retrieved successfully');

        } catch (\Exception $e) {
            Log::error('Error retrieving supplier performance: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve supplier performance', 500);
        }
    }

    /**
     * Get supplier analytics (placeholder for future implementation)
     */
    public function getAnalytics($id, Request $request): JsonResponse
    {
        try {
            $supplier = $this->getSupplier($id);
            $period = $request->get('period', '3months');

            $analytics = [
                'supplier' => $supplier,
                'period' => $period,
                'summary' => [
                    'total_value' => 0,
                    'order_frequency' => 'N/A',
                    'average_order_value' => 0,
                    'last_order_date' => null
                ],
                'message' => 'Detailed analytics will be available when supply order system is implemented'
            ];

            return $this->successResponse($analytics, 'Supplier analytics retrieved successfully');

        } catch (\Exception $e) {
            Log::error('Error retrieving supplier analytics: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve supplier analytics', 500);
        }
    }

    /**
     * Get supplier reliability metrics (placeholder for future implementation)
     */
    public function getReliability($id): JsonResponse
    {
        try {
            $supplier = $this->getSupplier($id);

            $reliability = [
                'supplier' => $supplier,
                'metrics' => [
                    'on_time_delivery_rate' => 0,
                    'quality_rating' => 0,
                    'communication_score' => 0,
                    'overall_reliability' => 0
                ],
                'message' => 'Reliability metrics will be available when supply order system is implemented'
            ];

            return $this->successResponse($reliability, 'Supplier reliability retrieved successfully');

        } catch (\Exception $e) {
            Log::error('Error retrieving supplier reliability: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve supplier reliability', 500);
        }
    }

    /**
     * Get supplier resources (placeholder)
     */
    public function getResources($id): JsonResponse
    {
        try {
            $supplier = $this->getSupplier($id);
            return $this->successResponse([], 'Supplier resources endpoint - implement when Resource model exists');
        } catch (\Exception $e) {
            Log::error('Error getting supplier resources: ' . $e->getMessage());
            return $this->errorResponse('Failed to get supplier resources', 500);
        }
    }

    /**
     * Add resource to supplier (placeholder)
     */
    public function addResource(Request $request, $id): JsonResponse
    {
        try {
            $supplier = $this->getSupplier($id);
            return $this->successResponse([], 'Add resource endpoint - implement when Resource model exists');
        } catch (\Exception $e) {
            Log::error('Error adding supplier resource: ' . $e->getMessage());
            return $this->errorResponse('Failed to add supplier resource', 500);
        }
    }

    /**
     * Update resource (placeholder)
     */
    public function updateResource(Request $request, $id, $resource): JsonResponse
    {
        try {
            $supplier = $this->getSupplier($id);
            return $this->successResponse([], 'Update resource endpoint - implement when Resource model exists');
        } catch (\Exception $e) {
            Log::error('Error updating supplier resource: ' . $e->getMessage());
            return $this->errorResponse('Failed to update supplier resource', 500);
        }
    }

    /**
     * Get supplier orders (placeholder)
     */
    public function getOrders($id): JsonResponse
    {
        try {
            $supplier = $this->getSupplier($id);
            return $this->successResponse([], 'Supplier orders endpoint - implement when Order model exists');
        } catch (\Exception $e) {
            Log::error('Error getting supplier orders: ' . $e->getMessage());
            return $this->errorResponse('Failed to get supplier orders', 500);
        }
    }

    /**
     * Create order for supplier (placeholder)
     */
    public function createOrder(Request $request, $id): JsonResponse
    {
        try {
            $supplier = $this->getSupplier($id);
            return $this->successResponse([], 'Create order endpoint - implement when Order model exists');
        } catch (\Exception $e) {
            Log::error('Error creating supplier order: ' . $e->getMessage());
            return $this->errorResponse('Failed to create supplier order', 500);
        }
    }

    /**
     * Update order status (placeholder)
     */
    public function updateOrderStatus(Request $request, $order): JsonResponse
    {
        try {
            return $this->successResponse([], 'Update order status endpoint - implement when Order model exists');
        } catch (\Exception $e) {
            Log::error('Error updating order status: ' . $e->getMessage());
            return $this->errorResponse('Failed to update order status', 500);
        }
    }

    /**
     * Track order (placeholder)
     */
    public function trackOrder($order): JsonResponse
    {
        try {
            return $this->successResponse([], 'Track order endpoint - implement when Order model exists');
        } catch (\Exception $e) {
            Log::error('Error tracking order: ' . $e->getMessage());
            return $this->errorResponse('Failed to track order', 500);
        }
    }

    /**
     * Get supplier batches (placeholder)
     */
    public function getBatches($id): JsonResponse
    {
        try {
            $supplier = $this->getSupplier($id);
            return $this->successResponse([], 'Supplier batches endpoint - implement when Batch model exists');
        } catch (\Exception $e) {
            Log::error('Error getting supplier batches: ' . $e->getMessage());
            return $this->errorResponse('Failed to get supplier batches', 500);
        }
    }

    /**
     * Create batch for supplier (placeholder)
     */
    public function createBatch(Request $request, $id): JsonResponse
    {
        try {
            $supplier = $this->getSupplier($id);
            return $this->successResponse([], 'Create batch endpoint - implement when Batch model exists');
        } catch (\Exception $e) {
            Log::error('Error creating supplier batch: ' . $e->getMessage());
            return $this->errorResponse('Failed to create supplier batch', 500);
        }
    }

    /**
     * Track batch (placeholder)
     */
    public function trackBatch($batch): JsonResponse
    {
        try {
            return $this->successResponse([], 'Track batch endpoint - implement when Batch model exists');
        } catch (\Exception $e) {
            Log::error('Error tracking batch: ' . $e->getMessage());
            return $this->errorResponse('Failed to track batch', 500);
        }
    }

    /**
     * Bulk import suppliers from CSV/Excel (simplified version)
     */
    public function bulkImport(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'file' => 'required|file|mimes:csv,txt',
                'override_duplicates' => 'boolean'
            ]);

            $file = $request->file('file');
            $overrideDuplicates = $request->boolean('override_duplicates', false);
            
            $csvContent = file_get_contents($file->path());
            $lines = explode("\n", $csvContent);
            $header = str_getcsv(array_shift($lines));
            
            $importedCount = 0;
            $duplicateCount = 0;
            $errorCount = 0;
            $errors = [];

            DB::beginTransaction();

            foreach ($lines as $lineNumber => $line) {
                if (empty(trim($line))) continue;

                try {
                    $data = str_getcsv($line);
                    $supplierData = array_combine($header, $data);

                    // Check for required name field
                    if (empty($supplierData['name'])) {
                        $errors[] = "Line " . ($lineNumber + 2) . ": Name is required";
                        $errorCount++;
                        continue;
                    }

                    // Check for duplicates
                    $existingSupplier = Supplier::where('name', $supplierData['name'])->first();
                    
                    if ($existingSupplier && !$overrideDuplicates) {
                        $duplicateCount++;
                        continue;
                    }

                    // Prepare contact_info array
                    $contactInfo = [];
                    if (!empty($supplierData['email'])) $contactInfo['email'] = $supplierData['email'];
                    if (!empty($supplierData['phone'])) $contactInfo['phone'] = $supplierData['phone'];
                    if (!empty($supplierData['contact_person'])) $contactInfo['person'] = $supplierData['contact_person'];

                    $newSupplierData = [
                        'name' => $supplierData['name'],
                        'contact_info' => !empty($contactInfo) ? $contactInfo : null,
                        'address' => $supplierData['address'] ?? null
                    ];

                    if ($existingSupplier && $overrideDuplicates) {
                        $existingSupplier->update($newSupplierData);
                    } else {
                        Supplier::create($newSupplierData);
                    }

                    $importedCount++;

                } catch (\Exception $e) {
                    $errors[] = "Line " . ($lineNumber + 2) . ": " . $e->getMessage();
                    $errorCount++;
                }
            }

            DB::commit();

            $result = [
                'imported_count' => $importedCount,
                'duplicate_count' => $duplicateCount,
                'error_count' => $errorCount,
                'errors' => $errors
            ];

            return $this->successResponse($result, 'Suppliers imported successfully');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error importing suppliers: ' . $e->getMessage());
            return $this->errorResponse('Failed to import suppliers', 500);
        }
    }
}