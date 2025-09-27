<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\UserController;
use App\Http\Controllers\API\HospitalController;
use App\Http\Controllers\API\RequestController;
use App\Http\Controllers\API\ResourceController;
use App\Http\Controllers\API\AllocationController;
use App\Http\Controllers\API\ResponderController;
use App\Http\Controllers\API\NotificationController;
use App\Http\Controllers\API\ForecastController;
use App\Http\Controllers\API\SystemController;

use Illuminate\Support\Facades\Log;

Log::info('API routes file is being loaded');

// Basic test routes
Route::get('/test', function () {
    return response()->json([
        'message' => 'API is working!',
    ]);
});

Route::get('/debug', function () {
    return response()->json(['api_file' => 'loaded']);
});

Route::get('/ping', function () {
    return response()->json(['message' => 'API is working']);
});

// Login route for auth exceptions
Route::get('login', function () {
    return response()->json([
        'success' => false,
        'error' => [
            'code' => 'AUTH_REQUIRED',
            'message' => 'Use /api/v1/auth/login endpoint for authentication'
        ]
    ], 401);
})->name('login');

// API Version 1 Routes
Route::prefix('v1')->group(function () {
    
    // ==========================================
    // PUBLIC ROUTES (No Authentication Required)
    // ==========================================
    
    // Simple test-log route without complex logging
    Route::get('/test-log', function () {
        try {
            // Use basic error_log instead of Laravel Log to avoid configuration issues
            error_log('Test log from /api/v1/test-log - basic logging works');
            
            return response()->json([
                'message' => 'Log test successful',
                'timestamp' => now()->toISOString()
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Test failed',
                'message' => $e->getMessage()
            ], 500);
        }
    });

    // Authentication Endpoints
    Route::prefix('auth')->group(function () {
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('/reset-password', [AuthController::class, 'resetPassword']);
    });

    // Public System Status
    Route::get('/system/health', [SystemController::class, 'health']);
    Route::get('/system/status', [SystemController::class, 'status']);
    
    // ==========================================
    // PROTECTED ROUTES (Authentication Required)
    // ==========================================
    
    Route::middleware(['auth:sanctum'])->group(function () {
        
        // Authentication Management
        Route::prefix('auth')->group(function () {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/me', [AuthController::class, 'me']);
            Route::put('/profile', [AuthController::class, 'updateProfile']);
            Route::put('/password', [AuthController::class, 'updatePassword']);
        });

        // [Rest of your protected routes remain exactly the same...]
        Route::apiResource('users', UserController::class);
        Route::prefix('users/{user}')->group(function () {
            Route::get('/profile', [UserController::class, 'profile']);
            Route::put('/profile', [UserController::class, 'updateProfile']);
            Route::get('/roles', [UserController::class, 'roles']);
            Route::put('/roles', [UserController::class, 'updateRoles']);
            Route::get('/permissions', [UserController::class, 'permissions']);
        });

        Route::apiResource('hospitals', HospitalController::class);
        Route::prefix('hospitals/{hospital}')->group(function () {
            Route::get('/resources', [HospitalController::class, 'resources']);
            Route::post('/resources', [HospitalController::class, 'addResource']);
            Route::put('/resources/{resource}', [HospitalController::class, 'updateResource']);
            Route::delete('/resources/{resource}', [HospitalController::class, 'removeResource']);
            Route::get('/capacity', [HospitalController::class, 'capacity']);
            Route::get('/utilization', [HospitalController::class, 'utilization']);
            Route::get('/performance', [HospitalController::class, 'performance']);
            Route::get('/responders', [HospitalController::class, 'responders']);
            Route::get('/vehicles', [HospitalController::class, 'vehicles']);
        });

        Route::prefix('hospitals')->group(function () {
            Route::get('/nearby', [HospitalController::class, 'nearby']);
            Route::get('/search', [HospitalController::class, 'search']);
            Route::post('/bulk-update', [HospitalController::class, 'bulkUpdate']);
        });

        Route::apiResource('resources', ResourceController::class);
        Route::prefix('resources')->group(function () {
            Route::get('/summary', [ResourceController::class, 'summary']);
            Route::get('/low-stock', [ResourceController::class, 'lowStock']);
            Route::get('/expiring', [ResourceController::class, 'expiring']);
            Route::get('/utilization', [ResourceController::class, 'utilization']);
            Route::post('/bulk-update', [ResourceController::class, 'bulkUpdate']);
            Route::post('/transfer', [ResourceController::class, 'transfer']);
            Route::get('/thresholds', [ResourceController::class, 'thresholds']);
            Route::put('/thresholds/{threshold}', [ResourceController::class, 'updateThreshold']);
        });

        Route::apiResource('requests', RequestController::class);
        Route::prefix('requests/{request}')->group(function () {
            Route::post('/assign', [RequestController::class, 'assign']);
            Route::put('/status', [RequestController::class, 'updateStatus']);
            Route::post('/complete', [RequestController::class, 'complete']);
            Route::post('/cancel', [RequestController::class, 'cancel']);
            Route::get('/assignments', [RequestController::class, 'assignments']);
            Route::get('/timeline', [RequestController::class, 'timeline']);
            Route::get('/tracking', [RequestController::class, 'tracking']);
        });

        Route::prefix('requests')->group(function () {
            Route::get('/active', [RequestController::class, 'active']);
            Route::get('/completed', [RequestController::class, 'completed']);
            Route::get('/critical', [RequestController::class, 'critical']);
            Route::get('/statistics', [RequestController::class, 'statistics']);
            Route::get('/by-location', [RequestController::class, 'byLocation']);
            Route::get('/by-type', [RequestController::class, 'byType']);
        });

        Route::prefix('allocations')->group(function () {
            Route::post('/', [AllocationController::class, 'create']);
            Route::get('/{allocation}', [AllocationController::class, 'show']);
            Route::put('/{allocation}', [AllocationController::class, 'update']);
            Route::delete('/{allocation}', [AllocationController::class, 'destroy']);
            Route::post('/optimize', [AllocationController::class, 'optimize']);
            Route::post('/simulate', [AllocationController::class, 'simulate']);
            Route::get('/algorithms', [AllocationController::class, 'algorithms']);
            Route::get('/performance', [AllocationController::class, 'performance']);
            Route::post('/test', [AllocationController::class, 'test']);
            Route::get('/test-results', [AllocationController::class, 'testResults']);
        });

        Route::apiResource('responders', ResponderController::class);
        Route::prefix('responders/{responder}')->group(function () {
            Route::get('/assignments', [ResponderController::class, 'assignments']);
            Route::get('/performance', [ResponderController::class, 'performance']);
            Route::put('/status', [ResponderController::class, 'updateStatus']);
            Route::get('/schedule', [ResponderController::class, 'schedule']);
        });

        Route::prefix('responders')->group(function () {
            Route::get('/available', [ResponderController::class, 'available']);
            Route::get('/by-hospital', [ResponderController::class, 'byHospital']);
            Route::get('/by-specialization', [ResponderController::class, 'bySpecialization']);
            Route::post('/bulk-assign', [ResponderController::class, 'bulkAssign']);
        });

        Route::prefix('forecasting')->group(function () {
            Route::post('/generate', [ForecastController::class, 'generate']);
            Route::get('/results', [ForecastController::class, 'results']);
            Route::get('/results/{forecast}', [ForecastController::class, 'show']);
            Route::delete('/results/{forecast}', [ForecastController::class, 'destroy']);
            Route::get('/historical', [ForecastController::class, 'historical']);
            Route::post('/historical', [ForecastController::class, 'addHistoricalData']);
            Route::get('/models', [ForecastController::class, 'models']);
            Route::get('/models/{model}/accuracy', [ForecastController::class, 'modelAccuracy']);
            Route::post('/models/{model}/retrain', [ForecastController::class, 'retrain']);
            Route::get('/decisions', [ForecastController::class, 'decisions']);
            Route::post('/decisions/{decision}/override', [ForecastController::class, 'overrideDecision']);
        });

        Route::prefix('notifications')->group(function () {
            Route::get('/', [NotificationController::class, 'index']);
            Route::get('/unread', [NotificationController::class, 'unread']);
            Route::put('/{notification}/read', [NotificationController::class, 'markAsRead']);
            Route::post('/mark-all-read', [NotificationController::class, 'markAllAsRead']);
            Route::delete('/{notification}', [NotificationController::class, 'destroy']);
            Route::get('/channels', [NotificationController::class, 'channels']);
            Route::post('/channels', [NotificationController::class, 'addChannel']);
            Route::put('/channels/{channel}', [NotificationController::class, 'updateChannel']);
            Route::delete('/channels/{channel}', [NotificationController::class, 'removeChannel']);
            Route::post('/broadcast', [NotificationController::class, 'broadcast']);
            Route::get('/logs', [NotificationController::class, 'logs']);
            Route::get('/statistics', [NotificationController::class, 'statistics']);
        });

        Route::prefix('system')->group(function () {
            Route::get('/performance', [SystemController::class, 'performance']);
            Route::get('/metrics', [SystemController::class, 'metrics']);
            Route::get('/analytics', [SystemController::class, 'analytics']);
            Route::get('/components', [SystemController::class, 'components']);
            Route::put('/components/{component}', [SystemController::class, 'updateComponent']);
            Route::get('/database/status', [SystemController::class, 'databaseStatus']);
            Route::post('/database/backup', [SystemController::class, 'backup']);
            Route::get('/cache/stats', [SystemController::class, 'cacheStats']);
            Route::post('/cache/clear', [SystemController::class, 'clearCache']);
            Route::get('/config', [SystemController::class, 'config']);
            Route::put('/config', [SystemController::class, 'updateConfig']);
        });

        Route::prefix('sensors')->group(function () {
            Route::get('/', [SystemController::class, 'sensors']);
            Route::get('/{sensor}', [SystemController::class, 'sensor']);
            Route::post('/{sensor}/readings', [SystemController::class, 'addReading']);
            Route::get('/{sensor}/readings', [SystemController::class, 'readings']);
            Route::get('/readings/latest', [SystemController::class, 'latestReadings']);
            Route::get('/readings/alerts', [SystemController::class, 'sensorAlerts']);
        });

        Route::prefix('logistics')->group(function () {
            Route::get('/vehicles', [SystemController::class, 'vehicles']);
            Route::get('/vehicles/{vehicle}/routes', [SystemController::class, 'vehicleRoutes']);
            Route::post('/vehicles/{vehicle}/assign', [SystemController::class, 'assignVehicle']);
            Route::post('/routes/optimize', [SystemController::class, 'optimizeRoute']);
            Route::get('/routes/active', [SystemController::class, 'activeRoutes']);
            Route::put('/routes/{route}/update', [SystemController::class, 'updateRoute']);
            Route::get('/deliveries', [SystemController::class, 'deliveries']);
            Route::get('/deliveries/{delivery}/tracking', [SystemController::class, 'trackDelivery']);
            Route::post('/deliveries/{delivery}/complete', [SystemController::class, 'completeDelivery']);
        });
    });
});

// Rate Limiting
Route::middleware(['throttle:api'])->group(function () {
    // All API routes are automatically rate-limited
});