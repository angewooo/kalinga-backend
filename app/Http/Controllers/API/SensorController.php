<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\RealTimeSensor;
use App\Models\SensorReading;
use App\Models\Hospital;
use App\Http\Requests\CreateSensorRequest;
use App\Http\Requests\UpdateSensorRequest;
use App\Http\Requests\SensorReadingRequest;
use App\Http\Traits\ApiResponseTrait;
use App\Services\SensorService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SensorController extends Controller
{
    use ApiResponseTrait;

    protected $sensorService;

    public function __construct(SensorService $sensorService)
    {
        $this->sensorService = $sensorService;
        
        // Apply middleware for different operations
        $this->middleware('permission:view-sensors')->only(['index', 'show']);
        $this->middleware('permission:create-sensors')->only(['store']);
        $this->middleware('permission:update-sensors')->only(['update']);
        $this->middleware('permission:delete-sensors')->only(['destroy']);
        $this->middleware('permission:manage-sensor-data')->only([
            'addReading', 'getReadings', 'bulkAddReadings'
        ]);
    }

    /**
     * Display a listing of sensors with filtering
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = $request->get('per_page', 15);
            $search = $request->get('search');
            $status = $request->get('status');
            $sensor_type = $request->get('sensor_type');
            $hospital_id = $request->get('hospital_id');
            $location = $request->get('location');

            $query = RealTimeSensor::with(['hospital', 'latestReading'])
                ->when($search, function($q) use ($search) {
                    $q->where('sensor_id', 'ILIKE', "%{$search}%")
                      ->orWhere('sensor_name', 'ILIKE', "%{$search}%")
                      ->orWhere('location', 'ILIKE', "%{$search}%");
                })
                ->when($status, function($q) use ($status) {
                    $q->where('status', $status);
                })
                ->when($sensor_type, function($q) use ($sensor_type) {
                    $q->where('sensor_type', $sensor_type);
                })
                ->when($hospital_id, function($q) use ($hospital_id) {
                    $q->where('hospital_id', $hospital_id);
                })
                ->when($location, function($q) use ($location) {
                    $q->where('location', 'ILIKE', "%{$location}%");
                });

            $sensors = $query->orderBy('sensor_name')->paginate($perPage);

            // Add real-time status to each sensor
            foreach ($sensors as $sensor) {
                $sensor->real_time_status = $this->getSensorRealTimeStatus($sensor);
            }

            return $this->successResponse($sensors, 'Sensors retrieved successfully');

        } catch (\Exception $e) {
            Log::error('Error retrieving sensors: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve sensors', 500);
        }
    }

    /**
     * Store a newly created sensor
     */
    public function store(CreateSensorRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();

            $sensorData = $request->validated();
            $sensorData['status'] = 'active';
            $sensorData['created_by'] = auth()->id();
            $sensorData['last_heartbeat'] = now();

            // Generate unique sensor ID if not provided
            if (!isset($sensorData['sensor_id'])) {
                $sensorData['sensor_id'] = $this->generateSensorId($sensorData['sensor_type']);
            }

            $sensor = RealTimeSensor::create($sensorData);

            // Initialize sensor monitoring
            $this->initializeSensorMonitoring($sensor);

            Log::info("Sensor created: {$sensor->sensor_id} at {$sensor->location}");

            DB::commit();

            $sensor->load(['hospital']);

            return $this->successResponse($sensor, 'Sensor created successfully', 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating sensor: ' . $e->getMessage());
            return $this->errorResponse('Failed to create sensor', 500);
        }
    }

    /**
     * Display the specified sensor with detailed information
     */
    public function show(RealTimeSensor $sensor): JsonResponse
    {
        try {
            $sensor->load(['hospital', 'sensorReadings' => function($q) {
                $q->latest()->limit(100);
            }]);

            // Get comprehensive sensor statistics
            $sensor->statistics = [
                'total_readings' => $sensor->sensorReadings()->count(),
                'readings_last_24h' => $sensor->sensorReadings()
                    ->where('timestamp', '>=', Carbon::now()->subDay())
                    ->count(),
                'average_reading_interval' => $this->calculateAverageReadingInterval($sensor),
                'uptime_percentage' => $this->calculateUptimePercentage($sensor),
                'last_reading_time' => $sensor->sensorReadings()->latest()->first()?->timestamp,
                'data_quality_score' => $this->calculateDataQualityScore($sensor),
                'battery_level' => $this->getCurrentBatteryLevel($sensor),
                'signal_strength' => $this->getCurrentSignalStrength($sensor)
            ];

            // Get recent analytics
            $sensor->analytics = $this->getSensorAnalytics($sensor, Carbon::now()->subDays(7));

            return $this->successResponse($sensor, 'Sensor details retrieved successfully');

        } catch (\Exception $e) {
            Log::error('Error retrieving sensor details: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve sensor details', 500);
        }
    }

    /**
     * Update the specified sensor
     */
    public function update(UpdateSensorRequest $request, RealTimeSensor $sensor): JsonResponse
    {
        try {
            DB::beginTransaction();

            $updateData = $request->validated();
            $updateData['updated_by'] = auth()->id();

            $sensor->update($updateData);

            Log::info("Sensor updated: {$sensor->sensor_id} by user " . auth()->id());

            DB::commit();

            $sensor->load(['hospital']);

            return $this->successResponse($sensor, 'Sensor updated successfully');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating sensor: ' . $e->getMessage());
            return $this->errorResponse('Failed to update sensor', 500);
        }
    }

    /**
     * Remove the specified sensor
     */
    public function destroy(RealTimeSensor $sensor): JsonResponse
    {
        try {
            DB::beginTransaction();

            // Check if sensor has recent readings
            $recentReadings = $sensor->sensorReadings()
                ->where('timestamp', '>=', Carbon::now()->subDays(7))
                ->count();

            if ($recentReadings > 0) {
                Log::warning("Attempting to delete sensor with recent readings: {$sensor->sensor_id}");
            }

            $sensor->delete();

            Log::info("Sensor deleted: {$sensor->sensor_id} by user " . auth()->id());

            DB::commit();

            return $this->successResponse(null, 'Sensor deleted successfully');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting sensor: ' . $e->getMessage());
            return $this->errorResponse('Failed to delete sensor', 500);
        }
    }

    /**
     * Add a sensor reading
     */
    public function addReading(Request $request, RealTimeSensor $sensor): JsonResponse
    {
        try {
            $request->validate([
                'reading_value' => 'required|numeric',
                'unit' => 'required|string|max:20',
                'timestamp' => 'nullable|date',
                'quality_indicator' => 'nullable|numeric|between:0,1',
                'metadata' => 'nullable|array'
            ]);

            DB::beginTransaction();

            $readingData = [
                'sensor_id' => $sensor->id,
                'reading_value' => $request->reading_value,
                'unit' => $request->unit,
                'timestamp' => $request->timestamp ?? now(),
                'quality_indicator' => $request->quality_indicator ?? 1.0,
                'metadata' => $request->metadata ?? []
            ];

            $reading = SensorReading::create($readingData);

            // Update sensor last reading and heartbeat
            $sensor->update([
                'last_reading_value' => $request->reading_value,
                'last_reading_timestamp' => $reading->timestamp,
                'last_heartbeat' => now()
            ]);

            // Check for alerts and anomalies
            $this->checkForAlerts($sensor, $reading);

            // Cache latest reading for real-time access
            Cache::put("sensor_latest_{$sensor->id}", $reading->toArray(), 3600);

            DB::commit();

            return $this->successResponse($reading, 'Sensor reading added successfully', 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error adding sensor reading: ' . $e->getMessage());
            return $this->errorResponse('Failed to add sensor reading', 500);
        }
    }

    /**
     * Get sensor readings with filtering
     */
    public function getReadings(RealTimeSensor $sensor, Request $request): JsonResponse
    {
        try {
            $limit = $request->get('limit', 100);
            $date_from = $request->get('date_from');
            $date_to = $request->get('date_to');
            $min_value = $request->get('min_value');
            $max_value = $request->get('max_value');
            $aggregation = $request->get('aggregation'); // hourly, daily, none

            $query = $sensor->sensorReadings();

            // Apply filters
            if ($date_from) {
                $query->where('timestamp', '>=', $date_from);
            }
            if ($date_to) {
                $query->where('timestamp', '<=', $date_to);
            }
            if ($min_value !== null) {
                $query->where('reading_value', '>=', $min_value);
            }
            if ($max_value !== null) {
                $query->where('reading_value', '<=', $max_value);
            }

            // Handle aggregation
            if ($aggregation === 'hourly') {
                $readings = $this->getHourlyAggregatedReadings($query);
            } elseif ($aggregation === 'daily') {
                $readings = $this->getDailyAggregatedReadings($query);
            } else {
                $readings = $query->orderBy('timestamp', 'desc')->limit($limit)->get();
            }

            // Add analytics if requested
            $response = ['readings' => $readings];
            if ($request->boolean('include_analytics')) {
                $response['analytics'] = $this->calculateReadingAnalytics($readings);
            }

            return $this->successResponse($response, 'Sensor readings retrieved successfully');

        } catch (\Exception $e) {
            Log::error('Error retrieving sensor readings: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve sensor readings', 500);
        }
    }

    /**
     * Get latest reading for sensor
     */
    public function getLatestReading(RealTimeSensor $sensor): JsonResponse
    {
        try {
            // Try cache first for performance
            $cachedReading = Cache::get("sensor_latest_{$sensor->id}");
            
            if ($cachedReading) {
                $reading = $cachedReading;
            } else {
                $reading = $sensor->sensorReadings()->latest('timestamp')->first();
                if ($reading) {
                    Cache::put("sensor_latest_{$sensor->id}", $reading->toArray(), 3600);
                }
            }

            if (!$reading) {
                return $this->errorResponse('No readings found for this sensor', 404);
            }

            // Add context information
            $response = [
                'sensor' => $sensor,
                'latest_reading' => $reading,
                'reading_age_minutes' => Carbon::parse($reading['timestamp'])->diffInMinutes(now()),
                'sensor_status' => $this->getSensorStatus($sensor),
                'alert_level' => $this->getAlertLevel($sensor, $reading)
            ];

            return $this->successResponse($response, 'Latest sensor reading retrieved successfully');

        } catch (\Exception $e) {
            Log::error('Error retrieving latest sensor reading: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve latest sensor reading', 500);
        }
    }

    /**
     * Get sensor analytics
     */
    public function getAnalytics(RealTimeSensor $sensor, Request $request): JsonResponse
    {
        try {
            $period = $request->get('period', '24hours');
            $startDate = $this->getStartDateFromPeriod($period);
            
            $analytics = [
                'sensor' => $sensor,
                'period' => $period,
                'summary' => [
                    'total_readings' => $sensor->sensorReadings()
                        ->where('timestamp', '>=', $startDate)
                        ->count(),
                    'average_value' => $sensor->sensorReadings()
                        ->where('timestamp', '>=', $startDate)
                        ->avg('reading_value'),
                    'min_value' => $sensor->sensorReadings()
                        ->where('timestamp', '>=', $startDate)
                        ->min('reading_value'),
                    'max_value' => $sensor->sensorReadings()
                        ->where('timestamp', '>=', $startDate)
                        ->max('reading_value'),
                    'std_deviation' => $this->calculateStandardDeviation($sensor, $startDate)
                ],
                'trends' => $this->calculateTrends($sensor, $startDate),
                'anomalies' => $this->detectAnomalies($sensor, $startDate),
                'data_quality' => [
                    'completeness' => $this->calculateDataCompleteness($sensor, $startDate),
                    'accuracy' => $this->calculateDataAccuracy($sensor, $startDate),
                    'timeliness' => $this->calculateDataTimeliness($sensor, $startDate)
                ],
                'alerts_triggered' => $this->getTriggeredAlerts($sensor, $startDate)
            ];

            return $this->successResponse($analytics, 'Sensor analytics retrieved successfully');

        } catch (\Exception $e) {
            Log::error('Error retrieving sensor analytics: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve sensor analytics', 500);
        }
    }

    /**
     * Activate sensor
     */
    public function activate(RealTimeSensor $sensor): JsonResponse
    {
        try {
            $sensor->update([
                'status' => 'active',
                'updated_by' => auth()->id()
            ]);

            // Initialize monitoring for newly activated sensor
            $this->initializeSensorMonitoring($sensor);

            Log::info("Sensor activated: {$sensor->sensor_id}");

            return $this->successResponse($sensor, 'Sensor activated successfully');

        } catch (\Exception $e) {
            Log::error('Error activating sensor: ' . $e->getMessage());
            return $this->errorResponse('Failed to activate sensor', 500);
        }
    }

    /**
     * Deactivate sensor
     */
    public function deactivate(RealTimeSensor $sensor): JsonResponse
    {
        try {
            $sensor->update([
                'status' => 'inactive',
                'updated_by' => auth()->id()
            ]);

            // Clear cached data for inactive sensor
            Cache::forget("sensor_latest_{$sensor->id}");
            Cache::forget("sensor_status_{$sensor->id}");

            Log::info("Sensor deactivated: {$sensor->sensor_id}");

            return $this->successResponse($sensor, 'Sensor deactivated successfully');

        } catch (\Exception $e) {
            Log::error('Error deactivating sensor: ' . $e->getMessage());
            return $this->errorResponse('Failed to deactivate sensor', 500);
        }
    }

    /**
     * Get comprehensive sensor status
     */
    public function getStatus(RealTimeSensor $sensor): JsonResponse
    {
        try {
            $status = Cache::remember("sensor_status_{$sensor->id}", 300, function() use ($sensor) {
                return [
                    'sensor_id' => $sensor->sensor_id,
                    'status' => $sensor->status,
                    'health_status' => $this->calculateHealthStatus($sensor),
                    'connectivity' => $this->getConnectivityStatus($sensor),
                    'battery_level' => $this->getCurrentBatteryLevel($sensor),
                    'signal_strength' => $this->getCurrentSignalStrength($sensor),
                    'last_heartbeat' => $sensor->last_heartbeat,
                    'uptime_percentage' => $this->calculateUptimePercentage($sensor),
                    'data_flow_status' => $this->getDataFlowStatus($sensor),
                    'alert_count_24h' => $this->getAlertCount($sensor, Carbon::now()->subDay()),
                    'calibration_status' => $this->getCalibrationStatus($sensor),
                    'maintenance_due' => $this->isMaintenanceDue($sensor)
                ];
            });

            return $this->successResponse($status, 'Sensor status retrieved successfully');

        } catch (\Exception $e) {
            Log::error('Error retrieving sensor status: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve sensor status', 500);
        }
    }

    /**
     * Calibrate sensor
     */
    public function calibrate(Request $request, RealTimeSensor $sensor): JsonResponse
    {
        try {
            $request->validate([
                'calibration_value' => 'required|numeric',
                'reference_value' => 'required|numeric',
                'calibration_method' => 'required|string',
                'notes' => 'nullable|string'
            ]);

            DB::beginTransaction();

            // Calculate calibration offset
            $offset = $request->reference_value - $request->calibration_value;

            // Update sensor calibration data
            $calibrationData = [
                'calibration_offset' => $offset,
                'last_calibration' => now(),
                'calibration_method' => $request->calibration_method,
                'calibration_notes' => $request->notes,
                'calibrated_by' => auth()->id()
            ];

            $sensor->update($calibrationData);

            // Log calibration event
            Log::info("Sensor calibrated: {$sensor->sensor_id} with offset: {$offset}");

            DB::commit();

            return $this->successResponse([
                'sensor' => $sensor,
                'calibration_offset' => $offset,
                'calibrated_at' => now()
            ], 'Sensor calibrated successfully');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error calibrating sensor: ' . $e->getMessage());
            return $this->errorResponse('Failed to calibrate sensor', 500);
        }
    }

    /**
     * Bulk add multiple sensor readings
     */
    public function bulkAddReadings(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'readings' => 'required|array',
                'readings.*.sensor_id' => 'required|exists:real_time_sensors,id',
                'readings.*.reading_value' => 'required|numeric',
                'readings.*.unit' => 'required|string|max:20',
                'readings.*.timestamp' => 'nullable|date'
            ]);

            DB::beginTransaction();

            $processedReadings = [];
            $errors = [];

            foreach ($request->readings as $index => $readingData) {
                try {
                    $reading = SensorReading::create([
                        'sensor_id' => $readingData['sensor_id'],
                        'reading_value' => $readingData['reading_value'],
                        'unit' => $readingData['unit'],
                        'timestamp' => $readingData['timestamp'] ?? now(),
                        'quality_indicator' => $readingData['quality_indicator'] ?? 1.0,
                        'metadata' => $readingData['metadata'] ?? []
                    ]);

                    // Update sensor last reading
                    $sensor = RealTimeSensor::find($readingData['sensor_id']);
                    $sensor->update([
                        'last_reading_value' => $readingData['reading_value'],
                        'last_reading_timestamp' => $reading->timestamp,
                        'last_heartbeat' => now()
                    ]);

                    // Cache latest reading
                    Cache::put("sensor_latest_{$sensor->id}", $reading->toArray(), 3600);

                    $processedReadings[] = $reading;

                } catch (\Exception $e) {
                    $errors[] = [
                        'index' => $index,
                        'error' => $e->getMessage(),
                        'data' => $readingData
                    ];
                }
            }

            DB::commit();

            $response = [
                'processed_count' => count($processedReadings),
                'error_count' => count($errors),
                'processed_readings' => $processedReadings,
                'errors' => $errors
            ];

            return $this->successResponse($response, 'Bulk sensor readings processed');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error processing bulk sensor readings: ' . $e->getMessage());
            return $this->errorResponse('Failed to process bulk sensor readings', 500);
        }
    }

    /**
     * Export sensor readings to CSV
     */
    public function exportReadings(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'sensor_ids' => 'required|array',
                'sensor_ids.*' => 'exists:real_time_sensors,id',
                'date_from' => 'required|date',
                'date_to' => 'required|date|after:date_from',
                'format' => 'in:csv,xlsx,json'
            ]);

            $exportResult = $this->sensorService->exportReadings(
                $request->sensor_ids,
                $request->date_from,
                $request->date_to,
                $request->format ?? 'csv'
            );

            return $this->successResponse($exportResult, 'Sensor readings exported successfully');

        } catch (\Exception $e) {
            Log::error('Error exporting sensor readings: ' . $e->getMessage());
            return $this->errorResponse('Failed to export sensor readings', 500);
        }
    }

    /**
     * Get sensor dashboard data
     */
    public function getDashboard(Request $request): JsonResponse
    {
        try {
            $hospital_id = $request->get('hospital_id');
            
            $query = RealTimeSensor::with(['hospital', 'latestReading']);
            
            if ($hospital_id) {
                $query->where('hospital_id', $hospital_id);
            }
            
            $sensors = $query->get();
            
            $dashboard = [
                'summary' => [
                    'total_sensors' => $sensors->count(),
                    'active_sensors' => $sensors->where('status', 'active')->count(),
                    'offline_sensors' => $sensors->filter(function($sensor) {
                        return $this->isSensorOffline($sensor);
                    })->count(),
                    'sensors_with_alerts' => $sensors->filter(function($sensor) {
                        return $this->hasActiveAlerts($sensor);
                    })->count()
                ],
                'sensor_types' => $sensors->groupBy('sensor_type')->map->count(),
                'recent_alerts' => $this->getRecentAlerts($sensors->pluck('id')->toArray()),
                'performance_metrics' => [
                    'average_uptime' => $this->calculateAverageUptime($sensors),
                    'data_quality_score' => $this->calculateOverallDataQuality($sensors),
                    'connectivity_rate' => $this->calculateConnectivityRate($sensors)
                ],
                'anomalies_24h' => $this->getRecentAnomalies($sensors->pluck('id')->toArray()),
                'maintenance_due' => $sensors->filter(function($sensor) {
                    return $this->isMaintenanceDue($sensor);
                })->values()
            ];

            return $this->successResponse($dashboard, 'Sensor dashboard data retrieved successfully');

        } catch (\Exception $e) {
            Log::error('Error retrieving sensor dashboard: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve sensor dashboard', 500);
        }
    }

    /**
     * Get live readings from all sensors
     */
    public function getLiveReadings(Request $request): JsonResponse
    {
        try {
            $hospital_id = $request->get('hospital_id');
            $sensor_types = $request->get('sensor_types', []);
            
            $query = RealTimeSensor::where('status', 'active');
            
            if ($hospital_id) {
                $query->where('hospital_id', $hospital_id);
            }
            
            if (!empty($sensor_types)) {
                $query->whereIn('sensor_type', $sensor_types);
            }
            
            $sensors = $query->get();
            
            $liveReadings = [];
            foreach ($sensors as $sensor) {
                $latestReading = Cache::get("sensor_latest_{$sensor->id}");
                
                if ($latestReading) {
                    $liveReadings[] = [
                        'sensor_id' => $sensor->sensor_id,
                        'sensor_name' => $sensor->sensor_name,
                        'sensor_type' => $sensor->sensor_type,
                        'location' => $sensor->location,
                        'hospital' => $sensor->hospital->hospital_name ?? 'Unknown',
                        'latest_reading' => $latestReading,
                        'status' => $this->getSensorStatus($sensor),
                        'alert_level' => $this->getAlertLevel($sensor, $latestReading)
                    ];
                }
            }

            return $this->successResponse([
                'timestamp' => now()->toISOString(),
                'total_sensors' => count($liveReadings),
                'readings' => $liveReadings
            ], 'Live sensor readings retrieved successfully');

        } catch (\Exception $e) {
            Log::error('Error retrieving live sensor readings: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve live sensor readings', 500);
        }
    }

    /**
     * Get sensor alerts
     */
    public function getAlerts(Request $request): JsonResponse
    {
        try {
            $severity = $request->get('severity');
            $status = $request->get('status', 'active');
            $hospital_id = $request->get('hospital_id');
            
            $alerts = $this->sensorService->getAlerts($severity, $status, $hospital_id);

            return $this->successResponse($alerts, 'Sensor alerts retrieved successfully');

        } catch (\Exception $e) {
            Log::error('Error retrieving sensor alerts: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve sensor alerts', 500);
        }
    }

    /**
     * Get sensor trends analysis
     */
    public function getTrends(Request $request): JsonResponse
    {
        try {
            $sensor_ids = $request->get('sensor_ids', []);
            $period = $request->get('period', '7days');
            $metric = $request->get('metric', 'average');
            
            $trends = $this->sensorService->calculateTrends($sensor_ids, $period, $metric);

            return $this->successResponse($trends, 'Sensor trends retrieved successfully');

        } catch (\Exception $e) {
            Log::error('Error retrieving sensor trends: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve sensor trends', 500);
        }
    }

    /**
     * Get sensor anomalies
     */
    public function getAnomalies(Request $request): JsonResponse
    {
        try {
            $sensor_ids = $request->get('sensor_ids', []);
            $period = $request->get('period', '24hours');
            $threshold = $request->get('threshold', 2.0); // Standard deviations
            
            $anomalies = $this->sensorService->detectAnomalies($sensor_ids, $period, $threshold);

            return $this->successResponse($anomalies, 'Sensor anomalies retrieved successfully');

        } catch (\Exception $e) {
            Log::error('Error retrieving sensor anomalies: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve sensor anomalies', 500);
        }
    }

    /**
     * Handle sensor webhook from external systems
     */
    public function handleSensorWebhook(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'sensor_id' => 'required|string',
                'event_type' => 'required|string',
                'data' => 'required|array',
                'timestamp' => 'required|date'
            ]);

            $sensor = RealTimeSensor::where('sensor_id', $request->sensor_id)->first();
            
            if (!$sensor) {
                return $this->errorResponse('Sensor not found', 404);
            }

            $result = $this->sensorService->processWebhookEvent(
                $sensor,
                $request->event_type,
                $request->data,
                $request->timestamp
            );

            return $this->successResponse($result, 'Sensor webhook processed successfully');

        } catch (\Exception $e) {
            Log::error('Error processing sensor webhook: ' . $e->getMessage());
            return $this->errorResponse('Failed to process sensor webhook', 500);
        }
    }

    // ================================
    // PRIVATE HELPER METHODS
    // ================================

    /**
     * Generate unique sensor ID
     */
    private function generateSensorId(string $sensorType): string
    {
        $prefix = strtoupper(substr($sensorType, 0, 4));
        $date = now()->format('ymd');
        $random = str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
        
        return $prefix . $date . $random;
    }

    /**
     * Initialize sensor monitoring
     */
    private function initializeSensorMonitoring(RealTimeSensor $sensor): void
    {
        // Set up initial monitoring parameters
        Cache::put("sensor_monitoring_{$sensor->id}", [
            'initialized_at' => now()->toISOString(),
            'expected_reading_interval' => $sensor->reading_interval_seconds ?? 300,
            'alert_thresholds' => [
                'min_value' => $sensor->min_threshold,
                'max_value' => $sensor->max_threshold,
                'offline_timeout' => 900 // 15 minutes
            ]
        ], 86400); // Cache for 24 hours
    }

    /**
     * Get sensor real-time status
     */
    private function getSensorRealTimeStatus(RealTimeSensor $sensor): array
    {
        $lastReading = Cache::get("sensor_latest_{$sensor->id}");
        
        return [
            'is_online' => $this->isSensorOnline($sensor),
            'last_reading_age_minutes' => $lastReading ? 
                Carbon::parse($lastReading['timestamp'])->diffInMinutes(now()) : null,
            'battery_level' => $this->getCurrentBatteryLevel($sensor),
            'signal_strength' => $this->getCurrentSignalStrength($sensor),
            'alert_count' => $this->getActiveAlertCount($sensor)
        ];
    }

    /**
     * Calculate average reading interval
     */
    private function calculateAverageReadingInterval(RealTimeSensor $sensor): ?float
    {
        $readings = $sensor->sensorReadings()
            ->orderBy('timestamp', 'desc')
            ->limit(100)
            ->pluck('timestamp')
            ->toArray();

        if (count($readings) < 2) return null;

        $intervals = [];
        for ($i = 0; $i < count($readings) - 1; $i++) {
            $current = Carbon::parse($readings[$i]);
            $next = Carbon::parse($readings[$i + 1]);
            $intervals[] = $current->diffInSeconds($next);
        }

        return array_sum($intervals) / count($intervals);
    }

    /**
     * Calculate uptime percentage
     */
    private function calculateUptimePercentage(RealTimeSensor $sensor): float
    {
        $totalHours = 24; // Last 24 hours
        $expectedReadings = ($totalHours * 3600) / ($sensor->reading_interval_seconds ?? 300);
        
        $actualReadings = $sensor->sensorReadings()
            ->where('timestamp', '>=', Carbon::now()->subDay())
            ->count();

        if ($expectedReadings == 0) return 0;

        return round(min(($actualReadings / $expectedReadings) * 100, 100), 2);
    }

    /**
     * Calculate data quality score
     */
    private function calculateDataQualityScore(RealTimeSensor $sensor): float
    {
        $recentReadings = $sensor->sensorReadings()
            ->where('timestamp', '>=', Carbon::now()->subDay())
            ->get();

        if ($recentReadings->isEmpty()) return 0;

        $qualityScore = $recentReadings->avg('quality_indicator') ?? 1.0;
        $completeness = min($recentReadings->count() / 288, 1); // Expected 288 readings per day (5min intervals)
        $timeliness = $this->calculateTimeliness($recentReadings);

        return round(($qualityScore * 0.4 + $completeness * 0.4 + $timeliness * 0.2) * 100, 2);
    }

    /**
     * Get current battery level
     */
    private function getCurrentBatteryLevel(RealTimeSensor $sensor): ?float
    {
        $latestReading = Cache::get("sensor_latest_{$sensor->id}");
        
        if (!$latestReading || !isset($latestReading['metadata']['battery_level'])) {
            return null;
        }

        return $latestReading['metadata']['battery_level'];
    }

    /**
     * Get current signal strength
     */
    private function getCurrentSignalStrength(RealTimeSensor $sensor): ?float
    {
        $latestReading = Cache::get("sensor_latest_{$sensor->id}");
        
        if (!$latestReading || !isset($latestReading['metadata']['signal_strength'])) {
            return null;
        }

        return $latestReading['metadata']['signal_strength'];
    }

    /**
     * Get sensor analytics for a period
     */
    private function getSensorAnalytics(RealTimeSensor $sensor, Carbon $startDate): array
    {
        $readings = $sensor->sensorReadings()
            ->where('timestamp', '>=', $startDate)
            ->get();

        return [
            'reading_count' => $readings->count(),
            'average_value' => $readings->avg('reading_value'),
            'min_value' => $readings->min('reading_value'),
            'max_value' => $readings->max('reading_value'),
            'variance' => $this->calculateVariance($readings),
            'trend' => $this->calculateTrend($readings)
        ];
    }

    /**
     * Check for alerts and anomalies
     */
    private function checkForAlerts(RealTimeSensor $sensor, SensorReading $reading): void
    {
        // Check threshold alerts
        if ($sensor->min_threshold && $reading->reading_value < $sensor->min_threshold) {
            $this->triggerAlert($sensor, 'LOW_THRESHOLD', $reading);
        }
        
        if ($sensor->max_threshold && $reading->reading_value > $sensor->max_threshold) {
            $this->triggerAlert($sensor, 'HIGH_THRESHOLD', $reading);
        }

        // Check for rapid changes
        $previousReading = $sensor->sensorReadings()
            ->where('id', '!=', $reading->id)
            ->latest('timestamp')
            ->first();

        if ($previousReading) {
            $changeRate = abs($reading->reading_value - $previousReading->reading_value);
            $timeInterval = Carbon::parse($reading->timestamp)->diffInMinutes($previousReading->timestamp);
            
            if ($timeInterval > 0 && ($changeRate / $timeInterval) > ($sensor->max_change_rate ?? 10)) {
                $this->triggerAlert($sensor, 'RAPID_CHANGE', $reading);
            }
        }

        // Check data quality
        if (($reading->quality_indicator ?? 1.0) < 0.8) {
            $this->triggerAlert($sensor, 'LOW_QUALITY', $reading);
        }
    }

    /**
     * Trigger sensor alert
     */
    private function triggerAlert(RealTimeSensor $sensor, string $alertType, SensorReading $reading): void
    {
        // Store alert in cache for quick access
        $alertKey = "sensor_alert_{$sensor->id}_{$alertType}";
        $alertData = [
            'sensor_id' => $sensor->id,
            'alert_type' => $alertType,
            'reading_id' => $reading->id,
            'reading_value' => $reading->reading_value,
            'timestamp' => $reading->timestamp,
            'severity' => $this->getAlertSeverity($alertType),
            'created_at' => now()->toISOString()
        ];

        Cache::put($alertKey, $alertData, 3600);

        // Log the alert
        Log::warning("Sensor alert triggered: {$alertType} for sensor {$sensor->sensor_id}", $alertData);
    }

    /**
     * Get alert severity level
     */
    private function getAlertSeverity(string $alertType): string
    {
        return match($alertType) {
            'LOW_THRESHOLD', 'HIGH_THRESHOLD' => 'high',
            'RAPID_CHANGE' => 'medium',
            'LOW_QUALITY' => 'low',
            'OFFLINE' => 'critical',
            default => 'medium'
        };
    }

    /**
     * Get start date from period string
     */
    private function getStartDateFromPeriod(string $period): Carbon
    {
        return match($period) {
            '1hour' => Carbon::now()->subHour(),
            '6hours' => Carbon::now()->subHours(6),
            '12hours' => Carbon::now()->subHours(12),
            '24hours' => Carbon::now()->subDay(),
            '7days' => Carbon::now()->subDays(7),
            '30days' => Carbon::now()->subDays(30),
            default => Carbon::now()->subDay()
        };
    }

    /**
     * Get hourly aggregated readings
     */
    private function getHourlyAggregatedReadings($query): array
    {
        return $query->selectRaw('
            DATE_TRUNC(\'hour\', timestamp) as hour,
            AVG(reading_value) as avg_value,
            MIN(reading_value) as min_value,
            MAX(reading_value) as max_value,
            COUNT(*) as reading_count
        ')
        ->groupBy('hour')
        ->orderBy('hour', 'desc')
        ->get()
        ->toArray();
    }

    /**
     * Get daily aggregated readings
     */
    private function getDailyAggregatedReadings($query): array
    {
        return $query->selectRaw('
            DATE_TRUNC(\'day\', timestamp) as day,
            AVG(reading_value) as avg_value,
            MIN(reading_value) as min_value,
            MAX(reading_value) as max_value,
            COUNT(*) as reading_count
        ')
        ->groupBy('day')
        ->orderBy('day', 'desc')
        ->get()
        ->toArray();
    }

    /**
     * Calculate reading analytics
     */
    private function calculateReadingAnalytics($readings): array
    {
        if (empty($readings) || (is_array($readings) && count($readings) === 0)) {
            return [];
        }

        $values = is_array($readings) ? 
            array_column($readings, 'reading_value') : 
            $readings->pluck('reading_value')->toArray();

        if (empty($values)) return [];

        return [
            'count' => count($values),
            'average' => array_sum($values) / count($values),
            'median' => $this->calculateMedian($values),
            'std_deviation' => $this->calculateStdDeviation($values),
            'min' => min($values),
            'max' => max($values),
            'range' => max($values) - min($values)
        ];
    }

    /**
     * Get sensor status
     */
    private function getSensorStatus(RealTimeSensor $sensor): string
    {
        if ($sensor->status !== 'active') return $sensor->status;
        
        $lastHeartbeat = Carbon::parse($sensor->last_heartbeat ?? $sensor->updated_at);
        $minutesSinceHeartbeat = $lastHeartbeat->diffInMinutes(now());

        if ($minutesSinceHeartbeat > 15) return 'offline';
        if ($minutesSinceHeartbeat > 10) return 'warning';
        
        return 'online';
    }

    /**
     * Get alert level for reading
     */
    private function getAlertLevel(RealTimeSensor $sensor, $reading): string
    {
        if (!$reading || !is_array($reading)) return 'none';
        
        $value = $reading['reading_value'] ?? 0;
        
        if ($sensor->min_threshold && $value < $sensor->min_threshold) return 'critical';
        if ($sensor->max_threshold && $value > $sensor->max_threshold) return 'critical';
        
        // Warning thresholds (90% of limits)
        if ($sensor->min_threshold && $value < ($sensor->min_threshold * 1.1)) return 'warning';
        if ($sensor->max_threshold && $value > ($sensor->max_threshold * 0.9)) return 'warning';
        
        return 'normal';
    }

    /**
     * Calculate health status
     */
    private function calculateHealthStatus(RealTimeSensor $sensor): string
    {
        $status = $this->getSensorStatus($sensor);
        $batteryLevel = $this->getCurrentBatteryLevel($sensor);
        $dataQuality = $this->calculateDataQualityScore($sensor);

        if ($status === 'offline' || ($batteryLevel && $batteryLevel < 10)) return 'critical';
        if ($status === 'warning' || ($batteryLevel && $batteryLevel < 25) || $dataQuality < 70) return 'warning';
        if ($dataQuality >= 90 && (!$batteryLevel || $batteryLevel > 75)) return 'excellent';
        
        return 'good';
    }

    /**
     * Get connectivity status
     */
    private function getConnectivityStatus(RealTimeSensor $sensor): array
    {
        $signalStrength = $this->getCurrentSignalStrength($sensor);
        $lastHeartbeat = Carbon::parse($sensor->last_heartbeat ?? $sensor->updated_at);
        
        return [
            'status' => $this->getSensorStatus($sensor),
            'signal_strength' => $signalStrength,
            'last_contact' => $lastHeartbeat->toISOString(),
            'minutes_since_contact' => $lastHeartbeat->diffInMinutes(now())
        ];
    }

    /**
     * Get data flow status
     */
    private function getDataFlowStatus(RealTimeSensor $sensor): array
    {
        $expectedInterval = $sensor->reading_interval_seconds ?? 300;
        $lastReading = $sensor->sensorReadings()->latest('timestamp')->first();
        
        if (!$lastReading) {
            return ['status' => 'no_data', 'message' => 'No readings available'];
        }

        $minutesSinceReading = Carbon::parse($lastReading->timestamp)->diffInMinutes(now());
        $expectedMinutes = $expectedInterval / 60;

        if ($minutesSinceReading > ($expectedMinutes * 3)) {
            return ['status' => 'stale', 'message' => 'Data flow interrupted'];
        } elseif ($minutesSinceReading > ($expectedMinutes * 1.5)) {
            return ['status' => 'delayed', 'message' => 'Data flow delayed'];
        }
        
        return ['status' => 'normal', 'message' => 'Data flowing normally'];
    }

    /**
     * Get alert count for period
     */
    private function getAlertCount(RealTimeSensor $sensor, Carbon $since): int
    {
        // Get alerts from cache
        $alertTypes = ['LOW_THRESHOLD', 'HIGH_THRESHOLD', 'RAPID_CHANGE', 'LOW_QUALITY', 'OFFLINE'];
        $alertCount = 0;

        foreach ($alertTypes as $type) {
            $alertKey = "sensor_alert_{$sensor->id}_{$type}";
            $alert = Cache::get($alertKey);
            
            if ($alert && Carbon::parse($alert['created_at'])->gte($since)) {
                $alertCount++;
            }
        }

        return $alertCount;
    }

    /**
     * Get calibration status
     */
    private function getCalibrationStatus(RealTimeSensor $sensor): array
    {
        $lastCalibration = $sensor->last_calibration;
        
        if (!$lastCalibration) {
            return ['status' => 'never_calibrated', 'message' => 'Sensor has never been calibrated'];
        }

        $daysSinceCalibration = Carbon::parse($lastCalibration)->diffInDays(now());
        $calibrationInterval = $sensor->calibration_interval_days ?? 90;

        if ($daysSinceCalibration > $calibrationInterval) {
            return ['status' => 'overdue', 'message' => 'Calibration overdue'];
        } elseif ($daysSinceCalibration > ($calibrationInterval * 0.8)) {
            return ['status' => 'due_soon', 'message' => 'Calibration due soon'];
        }

        return ['status' => 'current', 'message' => 'Calibration up to date'];
    }

    /**
     * Check if maintenance is due
     */
    private function isMaintenanceDue(RealTimeSensor $sensor): bool
    {
        $lastMaintenance = $sensor->last_maintenance ?? $sensor->created_at;
        $daysSinceMaintenance = Carbon::parse($lastMaintenance)->diffInDays(now());
        $maintenanceInterval = $sensor->maintenance_interval_days ?? 180;

        return $daysSinceMaintenance > $maintenanceInterval;
    }

    /**
     * Calculate standard deviation
     */
    private function calculateStandardDeviation(RealTimeSensor $sensor, Carbon $startDate): float
    {
        $values = $sensor->sensorReadings()
            ->where('timestamp', '>=', $startDate)
            ->pluck('reading_value')
            ->toArray();

        return $this->calculateStdDeviation($values);
    }

    /**
     * Calculate trends
     */
    private function calculateTrends(RealTimeSensor $sensor, Carbon $startDate): array
    {
        $readings = $sensor->sensorReadings()
            ->where('timestamp', '>=', $startDate)
            ->orderBy('timestamp')
            ->get();

        if ($readings->count() < 2) {
            return ['trend' => 'insufficient_data'];
        }

        $values = $readings->pluck('reading_value')->toArray();
        $slope = $this->calculateLinearRegression($values);

        return [
            'trend' => $slope > 0.1 ? 'increasing' : ($slope < -0.1 ? 'decreasing' : 'stable'),
            'slope' => $slope,
            'confidence' => $this->calculateTrendConfidence($values)
        ];
    }

    /**
     * Detect anomalies
     */
    private function detectAnomalies(RealTimeSensor $sensor, Carbon $startDate): array
    {
        $readings = $sensor->sensorReadings()
            ->where('timestamp', '>=', $startDate)
            ->get();

        if ($readings->count() < 10) {
            return ['anomalies' => [], 'count' => 0];
        }

        $values = $readings->pluck('reading_value')->toArray();
        $mean = array_sum($values) / count($values);
        $stdDev = $this->calculateStdDeviation($values);
        
        $anomalies = [];
        foreach ($readings as $reading) {
            $zScore = abs(($reading->reading_value - $mean) / $stdDev);
            if ($zScore > 2.0) { // 2 standard deviations
                $anomalies[] = [
                    'reading_id' => $reading->id,
                    'timestamp' => $reading->timestamp,
                    'value' => $reading->reading_value,
                    'z_score' => $zScore,
                    'severity' => $zScore > 3.0 ? 'high' : 'medium'
                ];
            }
        }

        return ['anomalies' => $anomalies, 'count' => count($anomalies)];
    }

    // Additional helper methods for calculations
    private function calculateDataCompleteness(RealTimeSensor $sensor, Carbon $startDate): float
    {
        $expectedReadings = $startDate->diffInMinutes(now()) / ($sensor->reading_interval_seconds / 60);
        $actualReadings = $sensor->sensorReadings()->where('timestamp', '>=', $startDate)->count();
        
        return $expectedReadings > 0 ? min(($actualReadings / $expectedReadings) * 100, 100) : 0;
    }

    private function calculateDataAccuracy(RealTimeSensor $sensor, Carbon $startDate): float
    {
        $readings = $sensor->sensorReadings()
            ->where('timestamp', '>=', $startDate)
            ->get();

        if ($readings->isEmpty()) return 0;

        return $readings->avg('quality_indicator') * 100;
    }

    private function calculateDataTimeliness(RealTimeSensor $sensor, Carbon $startDate): float
    {
        return $this->calculateTimeliness(
            $sensor->sensorReadings()
                ->where('timestamp', '>=', $startDate)
                ->get()
        );
    }

    private function calculateTimeliness($readings): float
    {
        // Placeholder for timeliness calculation
        // This would measure how close to expected intervals the readings are
        return 95.0; // Placeholder value
    }

    private function getTriggeredAlerts(RealTimeSensor $sensor, Carbon $startDate): array
    {
        $alertTypes = ['LOW_THRESHOLD', 'HIGH_THRESHOLD', 'RAPID_CHANGE', 'LOW_QUALITY', 'OFFLINE'];
        $alerts = [];

        foreach ($alertTypes as $type) {
            $alertKey = "sensor_alert_{$sensor->id}_{$type}";
            $alert = Cache::get($alertKey);
            
            if ($alert && Carbon::parse($alert['created_at'])->gte($startDate)) {
                $alerts[] = $alert;
            }
        }

        return $alerts;
    }

    private function calculateVariance($readings): float
    {
        $values = is_array($readings) ? 
            array_column($readings, 'reading_value') : 
            $readings->pluck('reading_value')->toArray();

        if (count($values) < 2) return 0;

        $mean = array_sum($values) / count($values);
        $squaredDiffs = array_map(fn($value) => pow($value - $mean, 2), $values);
        
        return array_sum($squaredDiffs) / (count($squaredDiffs) - 1);
    }

    private function calculateTrend($readings): string
    {
        $values = is_array($readings) ? 
            array_column($readings, 'reading_value') : 
            $readings->pluck('reading_value')->toArray();

        if (count($values) < 3) return 'insufficient_data';

        $slope = $this->calculateLinearRegression($values);
        
        if ($slope > 0.1) return 'increasing';
        if ($slope < -0.1) return 'decreasing';
        return 'stable';
    }

    private function calculateMedian(array $values): float
    {
        sort($values);
        $count = count($values);
        
        if ($count === 0) return 0;
        if ($count % 2 === 0) {
            return ($values[$count / 2 - 1] + $values[$count / 2]) / 2;
        }
        
        return $values[floor($count / 2)];
    }

    private function calculateStdDeviation(array $values): float
    {
        if (count($values) < 2) return 0;
        
        $mean = array_sum($values) / count($values);
        $squaredDiffs = array_map(fn($value) => pow($value - $mean, 2), $values);
        $variance = array_sum($squaredDiffs) / (count($squaredDiffs) - 1);
        
        return sqrt($variance);
    }

    private function calculateLinearRegression(array $values): float
    {
        $n = count($values);
        if ($n < 2) return 0;

        $sumX = $n * ($n - 1) / 2; // Sum of indices 0,1,2,...,n-1
        $sumY = array_sum($values);
        $sumXY = 0;
        $sumXSquared = 0;

        for ($i = 0; $i < $n; $i++) {
            $sumXY += $i * $values[$i];
            $sumXSquared += $i * $i;
        }

        $denominator = ($n * $sumXSquared) - ($sumX * $sumX);
        if ($denominator == 0) return 0;

        return (($n * $sumXY) - ($sumX * $sumY)) / $denominator;
    }

    private function calculateTrendConfidence(array $values): float
    {
        // Simplified confidence calculation based on data consistency
        if (count($values) < 5) return 0.5;
        
        $stdDev = $this->calculateStdDeviation($values);
        $mean = array_sum($values) / count($values);
        
        // Lower coefficient of variation = higher confidence
        $coefficientOfVariation = $mean != 0 ? $stdDev / abs($mean) : 1;
        
        return max(0, min(1, 1 - $coefficientOfVariation));
    }

    // Status check methods
    private function isSensorOnline(RealTimeSensor $sensor): bool
    {
        return $this->getSensorStatus($sensor) === 'online';
    }

    private function isSensorOffline(RealTimeSensor $sensor): bool
    {
        return $this->getSensorStatus($sensor) === 'offline';
    }

    private function hasActiveAlerts(RealTimeSensor $sensor): bool
    {
        return $this->getActiveAlertCount($sensor) > 0;
    }

    private function getActiveAlertCount(RealTimeSensor $sensor): int
    {
        return $this->getAlertCount($sensor, Carbon::now()->subDay());
    }

    // Dashboard helper methods
    private function getRecentAlerts(array $sensorIds): array
    {
        $alerts = [];
        $alertTypes = ['LOW_THRESHOLD', 'HIGH_THRESHOLD', 'RAPID_CHANGE', 'LOW_QUALITY', 'OFFLINE'];

        foreach ($sensorIds as $sensorId) {
            foreach ($alertTypes as $type) {
                $alertKey = "sensor_alert_{$sensorId}_{$type}";
                $alert = Cache::get($alertKey);
                
                if ($alert && Carbon::parse($alert['created_at'])->gte(Carbon::now()->subDay())) {
                    $alerts[] = $alert;
                }
            }
        }

        // Sort by timestamp, most recent first
        usort($alerts, function($a, $b) {
            return Carbon::parse($b['created_at'])->compare(Carbon::parse($a['created_at']));
        });

        return array_slice($alerts, 0, 20); // Return latest 20 alerts
    }

    private function calculateAverageUptime(iterable $sensors): float
    {
        $totalUptime = 0;
        $count = 0;

        foreach ($sensors as $sensor) {
            $totalUptime += $this->calculateUptimePercentage($sensor);
            $count++;
        }

        return $count > 0 ? round($totalUptime / $count, 2) : 0;
    }

    private function calculateOverallDataQuality(iterable $sensors): float
    {
        $totalQuality = 0;
        $count = 0;

        foreach ($sensors as $sensor) {
            $totalQuality += $this->calculateDataQualityScore($sensor);
            $count++;
        }

        return $count > 0 ? round($totalQuality / $count, 2) : 0;
    }

    private function calculateConnectivityRate(iterable $sensors): float
    {
        $onlineCount = 0;
        $totalCount = 0;

        foreach ($sensors as $sensor) {
            if ($this->isSensorOnline($sensor)) {
                $onlineCount++;
            }
            $totalCount++;
        }

        return $totalCount > 0 ? round(($onlineCount / $totalCount) * 100, 2) : 0;
    }

    private function getRecentAnomalies(array $sensorIds): array
    {
        $anomalies = [];
        
        foreach ($sensorIds as $sensorId) {
            $sensor = RealTimeSensor::find($sensorId);
            if ($sensor) {
                $sensorAnomalies = $this->detectAnomalies($sensor, Carbon::now()->subDay());
                $anomalies = array_merge($anomalies, $sensorAnomalies['anomalies']);
            }
        }

        // Sort by timestamp, most recent first
        usort($anomalies, function($a, $b) {
            return Carbon::parse($b['timestamp'])->compare(Carbon::parse($a['timestamp']));
        });

        return array_slice($anomalies, 0, 10); // Return latest 10 anomalies
    }
}