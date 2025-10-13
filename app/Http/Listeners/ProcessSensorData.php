<?php

namespace App\Listeners;

use App\Events\SensorReadingReceived;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class ProcessSensorData implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(SensorReadingReceived $event): void
    {
        $reading = $event->reading;
        $sensor = $event->sensor;
        $alert = $event->alert;

        // If there's an alert, send notifications
        if ($alert && $alert['breach_detected']) {
            $this->sendThresholdAlert($sensor, $reading, $alert);
        }

        // Analyze for anomalies (simple implementation)
        $this->analyzeForAnomalies($sensor, $reading);

        Log::info("Sensor data processed for sensor {$sensor->id}, reading {$reading->id}");
    }

    /**
     * Send threshold breach alert
     */
    private function sendThresholdAlert($sensor, $reading, $alert): void
    {
        try {
            // Get users to notify
            $usersToNotify = User::whereIn('role', ['admin', 'medical_staff'])
                ->where('is_active', true)
                ->get();

            $priority = $alert['severity'] === 'critical' ? 'critical' : 'high';
            $type = $alert['severity'] === 'critical' ? 'alert' : 'warning';

            foreach ($usersToNotify as $user) {
                Notification::create([
                    'user_id' => $user->id,
                    'type' => $type,
                    'title' => 'Sensor Threshold Breach',
                    'message' => "Sensor {$sensor->sensor_id} at {$sensor->location} has breached threshold. Current value: {$reading->value} {$sensor->unit_of_measurement}",
                    'priority' => $priority,
                    'action_url' => "/sensors/{$sensor->id}",
                    'is_read' => false,
                    'metadata' => json_encode([
                        'sensor_id' => $sensor->id,
                        'reading_id' => $reading->id,
                        'value' => $reading->value,
                        'threshold' => $alert['threshold'],
                        'breach_type' => $alert['breach_type'],
                        'severity' => $alert['severity'],
                    ]),
                ]);
            }

            Log::info("Sensor threshold alerts sent for sensor {$sensor->id}");
        } catch (\Exception $e) {
            Log::error("Failed to send sensor alerts: " . $e->getMessage());
        }
    }

    /**
     * Analyze sensor data for anomalies
     */
    private function analyzeForAnomalies($sensor, $reading): void
    {
        try {
            // Get recent readings (last 10)
            $recentReadings = \App\Models\SensorReading::where('sensor_id', $sensor->id)
                ->orderBy('timestamp', 'desc')
                ->limit(10)
                ->pluck('value')
                ->toArray();

            if (count($recentReadings) < 5) {
                return; // Not enough data for analysis
            }

            // Calculate mean and standard deviation
            $mean = array_sum($recentReadings) / count($recentReadings);
            $variance = array_sum(array_map(function($x) use ($mean) {
                return pow($x - $mean, 2);
            }, $recentReadings)) / count($recentReadings);
            $stdDev = sqrt($variance);

            // Check if current reading is an anomaly (more than 2 standard deviations)
            if (abs($reading->value - $mean) > (2 * $stdDev)) {
                Log::warning("Anomaly detected in sensor {$sensor->id}: value {$reading->value} deviates significantly from mean {$mean}");
                
                // Optionally send anomaly notification
                // $this->sendAnomalyAlert($sensor, $reading, $mean, $stdDev);
            }
        } catch (\Exception $e) {
            Log::error("Failed to analyze sensor anomalies: " . $e->getMessage());
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(SensorReadingReceived $event, \Throwable $exception): void
    {
        Log::error("ProcessSensorData failed: " . $exception->getMessage());
    }
}