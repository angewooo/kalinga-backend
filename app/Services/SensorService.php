<?php

namespace App\Services;

use App\Models\RealTimeSensor;
use App\Models\SensorReading;
use App\Models\Hospital;
use App\Models\ResourceThreshold;
use App\Models\HospitalResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class SensorService
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Process incoming sensor reading
     * 
     * @param array $data
     * @return SensorReading
     */
    public function processSensorReading(array $data)
    {
        DB::beginTransaction();
        
        try {
            // Validate sensor exists
            $sensor = RealTimeSensor::where('sensor_id', $data['sensor_id'])
                ->where('status', 'active')
                ->firstOrFail();

            // Create sensor reading
            $reading = SensorReading::create([
                'real_time_sensor_id' => $sensor->id,
                'reading_value' => $data['value'],
                'unit' => $data['unit'] ?? $sensor->unit,
                'reading_timestamp' => $data['timestamp'] ?? now(),
                'is_anomaly' => false,
                'notes' => $data['notes'] ?? null
            ]);

            // Check for anomalies
            $this->detectAnomalies($reading, $sensor);

            // Update sensor last reading
            $sensor->update([
                'last_reading' => $data['value'],
                'last_reading_timestamp' => $reading->reading_timestamp
            ]);

            // Check thresholds if sensor monitors resources
            if ($sensor->sensor_type === 'resource_level') {
                $this->checkResourceThresholds($sensor, $reading);
            }

            DB::commit();

            Log::info("Sensor reading processed", [
                'sensor_id' => $sensor->sensor_id,
                'reading_id' => $reading->id,
                'value' => $data['value']
            ]);

            return $reading;

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Sensor reading processing failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Check resource thresholds and trigger alerts
     * 
     * @param RealTimeSensor $sensor
     * @param SensorReading $reading
     */
    public function checkResourceThresholds(RealTimeSensor $sensor, SensorReading $reading)
    {
        try {
            $hospital = Hospital::find($sensor->hospital_id);
            
            if (!$hospital) {
                return;
            }

            // Get resource thresholds for this hospital
            $thresholds = ResourceThreshold::where('hospital_id', $hospital->id)
                ->where('is_active', true)
                ->get();

            foreach ($thresholds as $threshold) {
                // Get current resource level
                $resource = HospitalResource::where('hospital_id', $hospital->id)
                    ->where('resource_type', $threshold->resource_type)
                    ->first();

                if (!$resource) {
                    continue;
                }

                $currentLevel = $resource->current_quantity;

                // Check critical threshold
                if ($currentLevel <= $threshold->critical_threshold) {
                    $this->triggerThresholdAlert($threshold, $resource, 'critical', $currentLevel);
                }
                // Check warning threshold
                elseif ($currentLevel <= $threshold->warning_threshold) {
                    $this->triggerThresholdAlert($threshold, $resource, 'warning', $currentLevel);
                }
            }

        } catch (Exception $e) {
            Log::error("Threshold check failed: " . $e->getMessage());
        }
    }

    /**
     * Trigger alert when threshold is breached
     * 
     * @param ResourceThreshold $threshold
     * @param HospitalResource $resource
     * @param string $level
     * @param float $currentLevel
     */
    private function triggerThresholdAlert(ResourceThreshold $threshold, HospitalResource $resource, string $level, float $currentLevel)
    {
        try {
            // Check if alert was recently sent (avoid spam)
            $recentAlert = SensorReading::where('real_time_sensor_id', $resource->id)
                ->where('is_anomaly', true)
                ->where('reading_timestamp', '>=', now()->subMinutes(30))
                ->exists();

            if ($recentAlert) {
                return;
            }

            // Send notification
            $this->notificationService->sendNotification([
                'type' => 'threshold_breach',
                'title' => ucfirst($level) . ' Resource Level Alert',
                'message' => "Resource {$resource->resource_type} at {$resource->hospital->name} is at {$level} level: {$currentLevel} units",
                'priority' => $level === 'critical' ? 'critical' : 'high',
                'channels' => ['in_app', 'email'],
                'recipient_roles' => ['admin', 'dispatcher'],
                'additional_data' => [
                    'hospital_id' => $resource->hospital_id,
                    'resource_type' => $resource->resource_type,
                    'current_level' => $currentLevel,
                    'threshold_level' => $level,
                    'critical_threshold' => $threshold->critical_threshold,
                    'warning_threshold' => $threshold->warning_threshold
                ]
            ]);

            Log::warning("Threshold breach alert sent", [
                'resource_type' => $resource->resource_type,
                'hospital_id' => $resource->hospital_id,
                'level' => $level,
                'current_level' => $currentLevel
            ]);

        } catch (Exception $e) {
            Log::error("Threshold alert failed: " . $e->getMessage());
        }
    }

    /**
     * Detect anomalies in sensor readings
     * 
     * @param SensorReading $reading
     * @param RealTimeSensor $sensor
     */
    private function detectAnomalies(SensorReading $reading, RealTimeSensor $sensor)
    {
        try {
            // Get recent readings for comparison
            $recentReadings = SensorReading::where('real_time_sensor_id', $sensor->id)
                ->where('reading_timestamp', '>=', now()->subHours(24))
                ->orderBy('reading_timestamp', 'desc')
                ->limit(100)
                ->pluck('reading_value')
                ->toArray();

            if (count($recentReadings) < 10) {
                return; // Not enough data for anomaly detection
            }

            // Calculate statistics
            $mean = array_sum($recentReadings) / count($recentReadings);
            $variance = array_sum(array_map(function($x) use ($mean) {
                return pow($x - $mean, 2);
            }, $recentReadings)) / count($recentReadings);
            $stdDev = sqrt($variance);

            // Check if reading is outside 3 standard deviations (99.7% confidence)
            $zScore = abs(($reading->reading_value - $mean) / ($stdDev ?: 1));

            if ($zScore > 3) {
                $reading->update([
                    'is_anomaly' => true,
                    'notes' => ($reading->notes ?? '') . " Anomaly detected (z-score: " . round($zScore, 2) . ")"
                ]);

                // Send anomaly alert
                $this->notificationService->sendNotification([
                    'type' => 'sensor_anomaly',
                    'title' => 'Sensor Anomaly Detected',
                    'message' => "Unusual reading detected for sensor {$sensor->sensor_id}: {$reading->reading_value} {$reading->unit}",
                    'priority' => 'high',
                    'channels' => ['in_app'],
                    'recipient_roles' => ['admin'],
                    'additional_data' => [
                        'sensor_id' => $sensor->sensor_id,
                        'reading_value' => $reading->reading_value,
                        'expected_range' => [
                            'mean' => round($mean, 2),
                            'std_dev' => round($stdDev, 2)
                        ]
                    ]
                ]);

                Log::warning("Sensor anomaly detected", [
                    'sensor_id' => $sensor->sensor_id,
                    'reading_value' => $reading->reading_value,
                    'z_score' => $zScore
                ]);
            }

        } catch (Exception $e) {
            Log::error("Anomaly detection failed: " . $e->getMessage());
        }
    }

    /**
     * Get sensor readings with filters
     * 
     * @param array $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getSensorReadings(array $filters = [])
    {
        $query = SensorReading::query()->with('realTimeSensor');

        if (isset($filters['sensor_id'])) {
            $sensor = RealTimeSensor::where('sensor_id', $filters['sensor_id'])->first();
            if ($sensor) {
                $query->where('real_time_sensor_id', $sensor->id);
            }
        }

        if (isset($filters['start_time'])) {
            $query->where('reading_timestamp', '>=', $filters['start_time']);
        }

        if (isset($filters['end_time'])) {
            $query->where('reading_timestamp', '<=', $filters['end_time']);
        }

        if (isset($filters['is_anomaly'])) {
            $query->where('is_anomaly', $filters['is_anomaly']);
        }

        return $query->orderBy('reading_timestamp', 'desc')->get();
    }

    /**
     * Get sensor statistics
     * 
     * @param string $sensorId
     * @param int $hours
     * @return array
     */
    public function getSensorStatistics(string $sensorId, int $hours = 24)
    {
        try {
            $sensor = RealTimeSensor::where('sensor_id', $sensorId)->firstOrFail();

            $readings = SensorReading::where('real_time_sensor_id', $sensor->id)
                ->where('reading_timestamp', '>=', now()->subHours($hours))
                ->orderBy('reading_timestamp', 'asc')
                ->get();

            if ($readings->isEmpty()) {
                return [
                    'sensor_id' => $sensorId,
                    'message' => 'No readings available for the specified period'
                ];
            }

            $values = $readings->pluck('reading_value')->toArray();

            return [
                'sensor_id' => $sensorId,
                'sensor_type' => $sensor->sensor_type,
                'period_hours' => $hours,
                'total_readings' => $readings->count(),
                'latest_reading' => $readings->last()->reading_value,
                'latest_timestamp' => $readings->last()->reading_timestamp,
                'statistics' => [
                    'min' => min($values),
                    'max' => max($values),
                    'average' => round(array_sum($values) / count($values), 2),
                    'median' => $this->calculateMedian($values)
                ],
                'anomalies_detected' => $readings->where('is_anomaly', true)->count(),
                'readings' => $readings->map(function($reading) {
                    return [
                        'value' => $reading->reading_value,
                        'timestamp' => $reading->reading_timestamp,
                        'is_anomaly' => $reading->is_anomaly
                    ];
                })
            ];

        } catch (Exception $e) {
            Log::error("Get sensor statistics failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Register new sensor
     * 
     * @param array $data
     * @return RealTimeSensor
     */
    public function registerSensor(array $data)
    {
        try {
            $sensor = RealTimeSensor::create([
                'sensor_id' => $data['sensor_id'],
                'sensor_type' => $data['sensor_type'],
                'hospital_id' => $data['hospital_id'],
                'location' => $data['location'] ?? null,
                'unit' => $data['unit'],
                'status' => 'active',
                'last_reading' => null,
                'last_reading_timestamp' => null
            ]);

            Log::info("Sensor registered", [
                'sensor_id' => $sensor->sensor_id,
                'hospital_id' => $data['hospital_id']
            ]);

            return $sensor;

        } catch (Exception $e) {
            Log::error("Sensor registration failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Calculate median value
     * 
     * @param array $values
     * @return float
     */
    private function calculateMedian(array $values)
    {
        sort($values);
        $count = count($values);
        $middle = floor(($count - 1) / 2);

        if ($count % 2) {
            return $values[$middle];
        } else {
            return ($values[$middle] + $values[$middle + 1]) / 2;
        }
    }

    /**
     * Bulk process sensor readings
     * 
     * @param array $readings
     * @return array
     */
    public function bulkProcessReadings(array $readings)
    {
        $results = [
            'successful' => [],
            'failed' => []
        ];

        foreach ($readings as $reading) {
            try {
                $processed = $this->processSensorReading($reading);
                $results['successful'][] = $processed->id;
            } catch (Exception $e) {
                $results['failed'][] = [
                    'sensor_id' => $reading['sensor_id'] ?? 'unknown',
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }
}