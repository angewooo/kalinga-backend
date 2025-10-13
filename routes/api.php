<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\UserController;
use App\Http\Controllers\API\HospitalController;
use App\Http\Controllers\API\RequestController;
use App\Http\Controllers\API\ResourceController;
use App\Http\Controllers\API\AllocationController;
use App\Http\Controllers\API\ForecastController;
use App\Http\Controllers\API\SensorController;
use App\Http\Controllers\API\NotificationController;
use App\Http\Controllers\API\ResponderController;
use App\Http\Controllers\API\SupplierController;
use App\Http\Controllers\API\VehicleController;
use App\Http\Controllers\API\SystemController;
use App\Http\Controllers\API\AuthController;

// MINIMAL TEST - Add this temporarily at the top of your api.php routes
Route::put('users/{id}', [UserController::class, 'update']);

// ULTRA SIMPLE TEST - Add this at the VERY TOP
Route::get('/emergency-test', function() {
    return response()->json(['status' => 'OK', 'message' => 'Basic route working']);
});

// Add login route for authentication redirects
Route::get('login', function() {
    return response()->json([
        'error' => 'Authentication required',
        'message' => 'Please use /api/auth/login endpoint'
    ], 401);
})->name('login');

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// ================================
// PUBLIC ROUTES (No Authentication Required)
// ================================

// Authentication Routes
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
});

// System Health Check (Public for monitoring)
Route::get('system/health', [SystemController::class, 'health']);
Route::get('system/status', [SystemController::class, 'status']);



// ================================
// PROTECTED ROUTES (Authentication Required)
// ================================

Route::middleware('auth:sanctum')->group(function () {
    
    // Authentication Management
    Route::prefix('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('refresh', [AuthController::class, 'refresh']);
        Route::post('/change-password', [AuthController::class, 'updatePassword']);
    });

    // ================================
// USER MANAGEMENT ROUTES - FIXED
// ================================
Route::prefix('users')->group(function () {
    // Basic CRUD routes
    Route::get('/', [UserController::class, 'index']);
    Route::post('/', [UserController::class, 'store']);
    Route::get('/{id}', [UserController::class, 'show']);
    Route::put('/{id}', [UserController::class, 'update']); // ← THIS WAS MISSING
    Route::patch('/{id}', [UserController::class, 'update']);
    Route::delete('/{id}', [UserController::class, 'destroy']);
    
    // Profile routes
    Route::get('{user}/profile', [UserController::class, 'getProfile']);
    Route::put('{user}/profile', [UserController::class, 'updateProfile']);
    
    // Roles & Permissions routes
    Route::get('{user}/roles', [UserController::class, 'getRoles']);
    Route::post('{user}/roles', [UserController::class, 'updateRoles']);
    Route::delete('{user}/roles/{role}', [UserController::class, 'removeRole']);
    Route::get('{user}/permissions', [UserController::class, 'getPermissions']);
    
    // Activation routes
    Route::post('{user}/activate', [UserController::class, 'activate']);
    Route::post('{user}/deactivate', [UserController::class, 'deactivate']);
});

// Bulk User Operations
Route::prefix('users/bulk')->group(function () {
    Route::post('create', [UserController::class, 'bulkCreate']);
    Route::put('update', [UserController::class, 'bulkUpdate']);
    Route::delete('delete', [UserController::class, 'bulkDelete']);
    Route::post('import', [UserController::class, 'importUsers']);
    Route::get('export', [UserController::class, 'exportUsers']);
});

// ================================
// SUPPLIER MANAGEMENT ROUTES
// ================================
Route::prefix('suppliers')->middleware(['auth:sanctum'])->group(function () {    
    // SPECIFIC ROUTES FIRST (no parameters)
    Route::get('search', [SupplierController::class, 'search']);
    Route::get('statistics', [SupplierController::class, 'getStatistics']);
    
    // CORE CRUD ROUTES
    Route::get('/', [SupplierController::class, 'index']);           // List suppliers
    Route::post('/', [SupplierController::class, 'store']);          // Create supplier
    
    // SINGLE SUPPLIER ROUTES (use consistent parameter name 'id')
    Route::get('{id}', [SupplierController::class, 'show']);         // Get single supplier
    Route::put('{id}', [SupplierController::class, 'update']);       // Update supplier  
    Route::delete('{id}', [SupplierController::class, 'destroy']);   // Delete supplier
    
    // SUPPLIER-SPECIFIC FEATURE ROUTES (all use 'id' parameter)
    Route::get('{id}/resources', [SupplierController::class, 'getResources']);
    Route::post('{id}/resources', [SupplierController::class, 'addResource']);
    Route::put('{id}/resources/{resource}', [SupplierController::class, 'updateResource']);
    
    // Order routes
    Route::get('{id}/orders', [SupplierController::class, 'getOrders']);
    Route::post('{id}/orders', [SupplierController::class, 'createOrder']);
    Route::put('orders/{order}/status', [SupplierController::class, 'updateOrderStatus']);
    Route::get('orders/{order}/tracking', [SupplierController::class, 'trackOrder']);
    
    // Analytics routes
    Route::get('{id}/performance', [SupplierController::class, 'getPerformance']);
    Route::get('{id}/analytics', [SupplierController::class, 'getAnalytics']);
    Route::get('{id}/reliability', [SupplierController::class, 'getReliability']);
    
    // Batch routes
    Route::get('{id}/batches', [SupplierController::class, 'getBatches']);
    Route::post('{id}/batches', [SupplierController::class, 'createBatch']);
    Route::get('batches/{batch}/track', [SupplierController::class, 'trackBatch']);
});



    // ================================
    // HOSPITAL MANAGEMENT ROUTES
    // ================================
    Route::apiResource('hospitals', HospitalController::class);
    Route::prefix('hospitals')->group(function () {
        // Hospital Resources - FIXED method names
        Route::get('{hospital}/resources', [HospitalController::class, 'resources']);
        Route::post('{hospital}/resources', [HospitalController::class, 'addResource']);
        Route::put('{hospital}/resources/{resource}', [HospitalController::class, 'updateResource']);
        Route::delete('{hospital}/resources/{resource}', [HospitalController::class, 'removeResource']);
        
        // Add missing routes from your controller
        Route::get('{hospital}/responders', [HospitalController::class, 'responders']);
        Route::get('{hospital}/vehicles', [HospitalController::class, 'vehicles']);
        Route::get('{hospital}/utilization', [HospitalController::class, 'utilization']);
        Route::get('{hospital}/performance', [HospitalController::class, 'performance']);
        Route::get('{hospital}/capacity', [HospitalController::class, 'capacity']);
        Route::get('{hospital}/nearby', [HospitalController::class, 'nearby']);
        Route::get('{hospital}/search', [HospitalController::class, 'search']);
        
        // Resource Thresholds - check if these methods exist
        Route::get('{hospital}/thresholds', [HospitalController::class, 'getThresholds']);
        Route::post('{hospital}/thresholds', [HospitalController::class, 'setThreshold']);
        Route::put('{hospital}/thresholds/{threshold}', [HospitalController::class, 'updateThreshold']);
        
        // Inventory Managelllllllllment - check if these methods exist
        Route::get('{hospital}/inventory', [HospitalController::class, 'getInventory']);
        Route::get('{hospital}/inventory/logs', [HospitalController::class, 'getInventoryLogs']);
        Route::post('{hospital}/inventory/adjustment', [HospitalController::class, 'adjustInventory']);
        
        // Hospital Analytics - check if these methods exist
        Route::get('{hospital}/analytics', [HospitalController::class, 'getAnalytics']);
        Route::get('{hospital}/requests/history', [HospitalController::class, 'getRequestHistory']);
    });

    // Bulk Hospital Operations - check if these methods exist
    Route::prefix('hospitals/bulk')->group(function () {
        Route::post('create', [HospitalController::class, 'bulkCreate']);
        Route::put('update', [HospitalController::class, 'bulkUpdate']);
        Route::post('import-resources', [HospitalController::class, 'importResources']);
        Route::get('export-inventory', [HospitalController::class, 'exportInventory']);
    });


    // ================================
    // REQUEST MANAGEMENT ROUTES
    // ================================
    Route::apiResource('requests', RequestController::class);
    Route::prefix('requests')->group(function () {
        // Request Status Management
        Route::post('{request}/approve', [RequestController::class, 'approve']);
        Route::put('{request}/reject', [RequestController::class, 'reject']);
        Route::put('{request}/cancel', [RequestController::class, 'cancel']);
        Route::put('{request}/complete', [RequestController::class, 'complete']);
        Route::get('{request}/status-history', [RequestController::class, 'getStatusHistory']);
        
        // Request Tracking
        Route::get('{request}/tracking', [RequestController::class, 'getTracking']);
        Route::get('{request}/timeline', [RequestController::class, 'getTimeline']);
        Route::get('{request}/notifications', [RequestController::class, 'getNotifications']);
        
        // Request Analytics
        Route::get('{request}/analytics', [RequestController::class, 'getAnalytics']);
        Route::get('{request}/performance', [RequestController::class, 'getPerformance']);
    });

    // Request Filtering and Search
    Route::prefix('requests')->group(function () {
        Route::get('filter/status/{status}', [RequestController::class, 'filterByStatus']);
        Route::get('filter/urgency/{urgency}', [RequestController::class, 'filterByUrgency']);
        Route::get('filter/hospital/{hospital}', [RequestController::class, 'filterByHospital']);
        Route::get('filter/date-range', [RequestController::class, 'filterByDateRange']);
        Route::get('search', [RequestController::class, 'search']);
        Route::get('dashboard', [RequestController::class, 'getDashboard']);
    });

    // ================================
    // RESOURCE ALLOCATION ROUTES
    // ================================
    Route::apiResource('allocations', AllocationController::class);
    Route::prefix('allocations')->group(function () {
        // Allocation Process
        Route::post('calculate', [AllocationController::class, 'calculateAllocation']);
        Route::post('{allocation}/execute', [AllocationController::class, 'executeAllocation']);
        Route::post('{allocation}/optimize', [AllocationController::class, 'optimizeAllocation']);
        
        // Allocation Analytics
        Route::get('{allocation}/performance', [AllocationController::class, 'getPerformance']);
        Route::get('{allocation}/efficiency', [AllocationController::class, 'getEfficiency']);
        Route::get('algorithms/compare', [AllocationController::class, 'compareAlgorithms']);
        Route::get('algorithms/test', [AllocationController::class, 'testAlgorithms']);
        
        // Allocation Reports
        Route::get('reports/summary', [AllocationController::class, 'getSummaryReport']);
        Route::get('reports/performance', [AllocationController::class, 'getPerformanceReport']);
        Route::get('reports/optimization', [AllocationController::class, 'getOptimizationReport']);
    });

    // ================================
    // AI FORECASTING ROUTES
    // ================================
    Route::apiResource('forecasts', ForecastController::class);
    Route::prefix('forecasts')->group(function () {
        // Forecast Generation
        Route::post('generate', [ForecastController::class, 'generateForecast']);
        Route::post('batch-generate', [ForecastController::class, 'batchGenerateForecast']);
        Route::get('{forecast}/details', [ForecastController::class, 'getForecastDetails']);
        
        // Forecast Accuracy
        Route::get('{forecast}/accuracy', [ForecastController::class, 'getAccuracy']);
        Route::post('{forecast}/validate', [ForecastController::class, 'validateForecast']);
        Route::get('accuracy/summary', [ForecastController::class, 'getAccuracySummary']);
        
        // AI Model Management
        Route::get('models', [ForecastController::class, 'getModels']);
        Route::post('models/{model}/retrain', [ForecastController::class, 'retrainModel']);
        Route::get('models/{model}/performance', [ForecastController::class, 'getModelPerformance']);
        Route::post('models/compare', [ForecastController::class, 'compareModels']);
        
        // Historical Data
        Route::get('historical-demand', [ForecastController::class, 'getHistoricalDemand']);
        Route::post('historical-demand/import', [ForecastController::class, 'importHistoricalData']);
        Route::get('patterns/analysis', [ForecastController::class, 'analyzePatterns']);
    });

    // ================================
    // SENSOR & IOT ROUTES
    // ================================
    Route::apiResource('sensors', SensorController::class);
    Route::prefix('sensors')->group(function () {
        // Sensor Data Management
        Route::post('{sensor}/readings', [SensorController::class, 'addReading']);
        Route::get('{sensor}/readings', [SensorController::class, 'getReadings']);
        Route::get('{sensor}/latest', [SensorController::class, 'getLatestReading']);
        Route::get('{sensor}/analytics', [SensorController::class, 'getAnalytics']);
        
        // Sensor Status
        Route::put('{sensor}/activate', [SensorController::class, 'activate']);
        Route::put('{sensor}/deactivate', [SensorController::class, 'deactivate']);
        Route::get('{sensor}/status', [SensorController::class, 'getStatus']);
        Route::post('{sensor}/calibrate', [SensorController::class, 'calibrate']);
        
        // Bulk Sensor Operations
        Route::post('bulk/readings', [SensorController::class, 'bulkAddReadings']);
        Route::get('readings/export', [SensorController::class, 'exportReadings']);
        Route::get('dashboard', [SensorController::class, 'getDashboard']);
    });

    // Real-time Sensor Data
    Route::prefix('sensor-readings')->group(function () {
        Route::get('live', [SensorController::class, 'getLiveReadings']);
        Route::get('alerts', [SensorController::class, 'getAlerts']);
        Route::get('trends', [SensorController::class, 'getTrends']);
        Route::get('anomalies', [SensorController::class, 'getAnomalies']);
    });

    // ================================
    // NOTIFICATION ROUTES
    // ================================
    Route::apiResource('notifications', NotificationController::class);
    Route::prefix('notifications')->group(function () {
        // Notification Management
        Route::put('{notification}/read', [NotificationController::class, 'markAsRead']);
        Route::put('mark-all-read', [NotificationController::class, 'markAllAsRead']);
        Route::delete('{notification}/dismiss', [NotificationController::class, 'dismiss']);
        
        // Notification Filtering
        Route::get('unread', [NotificationController::class, 'getUnread']);
        Route::get('filter/type/{type}', [NotificationController::class, 'filterByType']);
        Route::get('filter/priority/{priority}', [NotificationController::class, 'filterByPriority']);
        Route::get('recent', [NotificationController::class, 'getRecent']);
        
        // Notification Settings
        Route::get('settings', [NotificationController::class, 'getSettings']);
        Route::put('settings', [NotificationController::class, 'updateSettings']);
        Route::get('channels', [NotificationController::class, 'getChannels']);
        Route::post('test-notification', [NotificationController::class, 'testNotification']);
    });

    // ================================
    // RESPONDER MANAGEMENT ROUTES
    // ================================
    Route::apiResource('responders', ResponderController::class);
    Route::prefix('responders')->group(function () {
        // Responder Availability
        Route::put('{responder}/available', [ResponderController::class, 'setAvailable']);
        Route::put('{responder}/unavailable', [ResponderController::class, 'setUnavailable']);
        Route::get('{responder}/status', [ResponderController::class, 'getStatus']);
        Route::get('available', [ResponderController::class, 'getAvailable']);
        
        // Responder Assignments
        Route::get('{responder}/assignments', [ResponderController::class, 'getAssignments']);
        Route::get('{responder}/assignments/active', [ResponderController::class, 'getActiveAssignments']);
        Route::get('{responder}/assignments/history', [ResponderController::class, 'getAssignmentHistory']);
        
        // Responder Performance
        Route::get('{responder}/performance', [ResponderController::class, 'getPerformance']);
        Route::get('{responder}/ratings', [ResponderController::class, 'getRatings']);
        Route::post('{responder}/rating', [ResponderController::class, 'addRating']);
        
        // Location Tracking
        Route::post('{responder}/location', [ResponderController::class, 'updateLocation']);
        Route::get('{responder}/location', [ResponderController::class, 'getLocation']);
        Route::get('{responder}/route', [ResponderController::class, 'getCurrentRoute']);
    });

    // ================================
    // VEHICLE & TRANSPORT ROUTES
    // ================================
    Route::apiResource('vehicles', VehicleController::class);
    Route::prefix('vehicles')->group(function () {
        // Vehicle Status
        Route::put('{vehicle}/available', [VehicleController::class, 'setAvailable']);
        Route::put('{vehicle}/maintenance', [VehicleController::class, 'setMaintenance']);
        Route::get('{vehicle}/status', [VehicleController::class, 'getStatus']);
        Route::get('available', [VehicleController::class, 'getAvailable']);
        
        // Vehicle Assignments
        Route::get('{vehicle}/assignments', [VehicleController::class, 'getAssignments']);
        Route::get('{vehicle}/assignments/active', [VehicleController::class, 'getActiveAssignments']);
        Route::get('{vehicle}/routes', [VehicleController::class, 'getRoutes']);
        
        // Vehicle Performance
        Route::get('{vehicle}/performance', [VehicleController::class, 'getPerformance']);
        Route::get('{vehicle}/maintenance-history', [VehicleController::class, 'getMaintenanceHistory']);
        Route::post('{vehicle}/maintenance-log', [VehicleController::class, 'addMaintenanceLog']);
        
        // Location Tracking
        Route::post('{vehicle}/location', [VehicleController::class, 'updateLocation']);
        Route::get('{vehicle}/location', [VehicleController::class, 'getCurrentLocation']);
        Route::get('{vehicle}/tracking-history', [VehicleController::class, 'getTrackingHistory']);
    });

    // Transport Routes
    Route::prefix('transport-routes')->group(function () {
        Route::get('optimize', [VehicleController::class, 'optimizeRoutes']);
        Route::post('calculate', [VehicleController::class, 'calculateRoute']);
        Route::get('active', [VehicleController::class, 'getActiveRoutes']);
        Route::get('{route}/progress', [VehicleController::class, 'getRouteProgress']);
    });

    // ================================
    // ASSIGNMENT MANAGEMENT ROUTES
    // ================================
    Route::prefix('assignments')->group(function () {
        Route::get('/', [AllocationController::class, 'getAssignments']);
        Route::post('/', [AllocationController::class, 'createAssignment']);
        Route::get('{assignment}', [AllocationController::class, 'getAssignment']);
        Route::put('{assignment}', [AllocationController::class, 'updateAssignment']);
        Route::delete('{assignment}', [AllocationController::class, 'deleteAssignment']);
        
        // Assignment Status
        Route::put('{assignment}/accept', [AllocationController::class, 'acceptAssignment']);
        Route::put('{assignment}/start', [AllocationController::class, 'startAssignment']);
        Route::put('{assignment}/complete', [AllocationController::class, 'completeAssignment']);
        Route::put('{assignment}/cancel', [AllocationController::class, 'cancelAssignment']);
        
        // Assignment Tracking
        Route::get('{assignment}/tracking', [AllocationController::class, 'trackAssignment']);
        Route::get('{assignment}/progress', [AllocationController::class, 'getAssignmentProgress']);
        Route::post('{assignment}/update-location', [AllocationController::class, 'updateAssignmentLocation']);
        
        // Assignment Analytics
        Route::get('analytics/dashboard', [AllocationController::class, 'getAssignmentDashboard']);
        Route::get('analytics/performance', [AllocationController::class, 'getAssignmentPerformance']);
        Route::get('filter/status/{status}', [AllocationController::class, 'filterAssignmentsByStatus']);
    });

    // ================================
    // SYSTEM MONITORING ROUTES
    // ================================
    Route::prefix('system')->group(function () {
        // System Health
        Route::get('health/detailed', [SystemController::class, 'detailedHealthCheck']);
        Route::get('performance', [SystemController::class, 'getPerformance']);
        Route::get('metrics', [SystemController::class, 'getMetrics']);
        Route::get('logs', [SystemController::class, 'getSystemLogs']);
        
        // Database Monitoring
        Route::get('database/status', [SystemController::class, 'getDatabaseStatusEndpoint']);
        Route::get('database/performance', [SystemController::class, 'getDatabasePerformance']);
        Route::get('database/connections', [SystemController::class, 'getDatabaseConnections']);
        
        // API Monitoring
        Route::get('api/statistics', [SystemController::class, 'getApiStatistics']);
        Route::get('api/response-times', [SystemController::class, 'getApiResponseTimes']);
        Route::get('api/error-rates', [SystemController::class, 'getApiErrorRates']);
        
        // Cache Monitoring
        Route::get('cache/status', [SystemController::class, 'getCacheStatusEndpoint']);
        Route::get('cache/statistics', [SystemController::class, 'getCacheStatistics']);
        Route::post('cache/clear', [SystemController::class, 'clearCache']);
        
        // Queue Monitoring
        Route::get('queues/status', [SystemController::class, 'getQueueStatus']);
        Route::get('queues/jobs', [SystemController::class, 'getQueueJobs']);
        Route::get('queues/failed', [SystemController::class, 'getFailedJobs']);
        Route::post('queues/retry/{job}', [SystemController::class, 'retryFailedJob']);
    });

    // ================================
    // ANALYTICS & REPORTING ROUTES
    // ================================
    Route::prefix('analytics')->group(function () {
        // Dashboard Analytics
        Route::get('dashboard', [SystemController::class, 'getAnalyticsDashboard']);
        Route::get('kpi', [SystemController::class, 'getKPIs']);
        Route::get('trends', [SystemController::class, 'getTrends']);
        Route::get('summary', [SystemController::class, 'getAnalyticsSummary']);
        
        // Request Analytics
        Route::get('requests/volume', [RequestController::class, 'getRequestVolume']);
        Route::get('requests/response-time', [RequestController::class, 'getResponseTime']);
        Route::get('requests/success-rate', [RequestController::class, 'getSuccessRate']);
        
        // Resource Analytics
        Route::get('resources/utilization', [ResourceController::class, 'getUtilization']);
        Route::get('resources/availability', [ResourceController::class, 'getAvailability']);
        Route::get('resources/demand', [ResourceController::class, 'getDemandAnalytics']);
        
        // Allocation Analytics
        Route::get('allocations/efficiency', [AllocationController::class, 'getAllocationEfficiency']);
        Route::get('allocations/optimization', [AllocationController::class, 'getOptimizationMetrics']);
        
        // Forecasting Analytics
        Route::get('forecasts/accuracy-trends', [ForecastController::class, 'getAccuracyTrends']);
        Route::get('forecasts/prediction-vs-actual', [ForecastController::class, 'getPredictionVsActual']);
        
        // Export Reports
        Route::get('export/requests', [SystemController::class, 'exportRequestReport']);
        Route::get('export/allocations', [SystemController::class, 'exportAllocationReport']);
        Route::get('export/performance', [SystemController::class, 'exportPerformanceReport']);
    });

    // ================================
    // BULK OPERATIONS ROUTES
    // ================================
    Route::prefix('bulk')->group(function () {
        // Bulk Data Operations
        Route::post('import/hospitals', [HospitalController::class, 'bulkImport']);
        Route::post('import/resources', [ResourceController::class, 'bulkImport']);
        Route::post('import/users', [UserController::class, 'bulkImport']);
        Route::post('import/suppliers', [SupplierController::class, 'bulkImport']);
        
        // Bulk Export Operations
        Route::get('export/all-data', [SystemController::class, 'exportAllData']);
        Route::get('export/system-backup', [SystemController::class, 'exportSystemBackup']);
        
        // Bulk Update Operations
        Route::put('update/resources', [ResourceController::class, 'bulkUpdate']);
        Route::put('update/thresholds', [HospitalController::class, 'bulkUpdateThresholds']);
        Route::put('update/user-roles', [UserController::class, 'bulkUpdateRoles']);
    });

});

// ================================
// RATE LIMITED ROUTES
// ================================

// High-frequency endpoints with stricter rate limiting
Route::middleware(['auth:sanctum', 'throttle:sensor-data'])->group(function () {
    Route::post('sensor-readings/bulk', [SensorController::class, 'bulkAddReadings']);
    Route::post('real-time/location-updates', [ResponderController::class, 'bulkLocationUpdate']);
    Route::post('real-time/vehicle-tracking', [VehicleController::class, 'bulkLocationUpdate']);
});

// AI/ML endpoints with moderate rate limiting
Route::middleware(['auth:sanctum', 'throttle:ai-operations'])->group(function () {
    Route::post('forecasts/generate', [ForecastController::class, 'generateForecast']);
    Route::post('allocations/calculate', [AllocationController::class, 'calculateAllocation']);
    Route::post('ai/decisions', [ForecastController::class, 'makeAiDecision']);
});

// ================================
// WEBHOOK ROUTES (External Systems)
// ================================
Route::prefix('webhooks')->group(function () {
    Route::post('supplier-updates', [SupplierController::class, 'handleSupplierWebhook']);
    Route::post('sensor-data', [SensorController::class, 'handleSensorWebhook']);
    Route::post('transport-updates', [VehicleController::class, 'handleTransportWebhook']);
    Route::post('external-requests', [RequestController::class, 'handleExternalRequest']);
});

// ================================
// FALLBACK ROUTE
// ================================
Route::fallback(function () {
    return response()->json([
        'success' => false,
        'error' => [
            'code' => 'ROUTE_NOT_FOUND',
            'message' => 'API endpoint not found.',
            'details' => 'Please check the API documentation for valid endpoints.'
        ],
        'meta' => [
            'timestamp' => now()->toISOString(),
            'version' => 'v1'
        ]
    ], 404);
});