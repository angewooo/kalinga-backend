<?php

namespace App\Services;

use App\Models\SystemHealth;
use App\Models\SystemPerformance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Exception;

class SystemMonitoringService
{
    /**
     * Check overall system health
     * 
     * @return SystemHealth
     */
    public function checkSystemHealth()
    {
        try {
            // Check various system components
            $databaseStatus = $this->checkDatabaseHealth();
            $cacheStatus = $this->checkCacheHealth();
            $apiStatus = $this->checkApiHealth();
            $queueStatus = $this->checkQueueHealth();

            // Calculate overall health score
            $healthScore = $this->calculateHealthScore([
                $databaseStatus,
                $cacheStatus,
                $apiStatus,
                $queueStatus
            ]);

            // Determine overall status
            $overallStatus = $this->determineOverallStatus($healthScore);

            // Create or update health record
            $health = SystemHealth::create([
                'health_status' => $overallStatus,
                'database_status' => $databaseStatus['status'],
                'cache_status' => $cacheStatus['status'],
                'api_status' => $apiStatus['status'],
                'queue_status' => $queueStatus['status'],
                'error_rate' => $this->calculateErrorRate(),
                'uptime_percentage' => $this->calculateUptime(),
                'last_incident' => $this->getLastIncident(),
                'health_score' => $healthScore,
                'checked_at' => now()
            ]);

            // Send alerts if health is critical
            if ($overallStatus === 'critical') {
                $this->sendHealthAlert($health);
            }

            Log::info("System health checked", [
                'status' => $overallStatus,
                'score' => $healthScore
            ]);

            return $health;

        } catch (Exception $e) {
            Log::error("System health check failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Record system performance metrics
     * 
     * @param array $metrics
     * @return SystemPerformance
     */
    public function recordPerformanceMetrics(array $metrics = [])
    {
        try {
            // Gather metrics if not provided
            if (empty($metrics)) {
                $metrics = $this->gatherPerformanceMetrics();
            }

            $performance = SystemPerformance::create([
                'metric_name' => $metrics['metric_name'] ?? 'general_performance',
                'metric_value' => $metrics['metric_value'] ?? 0,
                'response_time_ms' => $metrics['response_time_ms'] ?? $this->getAverageResponseTime(),
                'throughput_requests_per_second' => $metrics['throughput'] ?? $this->calculateThroughput(),
                'cpu_usage_percentage' => $metrics['cpu_usage'] ?? $this->getCpuUsage(),
                'memory_usage_mb' => $metrics['memory_usage'] ?? $this->getMemoryUsage(),
                'disk_usage_percentage' => $metrics['disk_usage'] ?? $this->getDiskUsage(),
                'active_connections' => $metrics['active_connections'] ?? $this->getActiveConnections(),
                'recorded_at' => now()
            ]);

            Log::info("Performance metrics recorded", [
                'metric_name' => $performance->metric_name,
                'response_time' => $performance->response_time_ms
            ]);

            return $performance;

        } catch (Exception $e) {
            Log::error("Performance recording failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Generate comprehensive health report
     * 
     * @param int $hours
     * @return array
     */
    public function generateHealthReport(int $hours = 24)
    {
        try {
            $startTime = now()->subHours($hours);

            // Get recent health checks
            $healthChecks = SystemHealth::where('checked_at', '>=', $startTime)
                ->orderBy('checked_at', 'desc')
                ->get();

            // Get performance metrics
            $performanceMetrics = SystemPerformance::where('recorded_at', '>=', $startTime)
                ->get();

            // Calculate statistics
            $avgHealthScore = $healthChecks->avg('health_score');
            $avgResponseTime = $performanceMetrics->avg('response_time_ms');
            $avgCpuUsage = $performanceMetrics->avg('cpu_usage_percentage');
            $avgMemoryUsage = $performanceMetrics->avg('memory_usage_mb');

            // Count issues
            $criticalCount = $healthChecks->where('health_status', 'critical')->count();
            $warningCount = $healthChecks->where('health_status', 'warning')->count();

            // Database statistics
            $dbStats = $this->getDatabaseStatistics();

            // API statistics
            $apiStats = $this->getApiStatistics($hours);

            return [
                'report_period' => [
                    'start' => $startTime->toISOString(),
                    'end' => now()->toISOString(),
                    'hours' => $hours
                ],
                'overall_health' => [
                    'current_status' => $healthChecks->first()?->health_status ?? 'unknown',
                    'average_health_score' => round($avgHealthScore, 2),
                    'total_checks' => $healthChecks->count(),
                    'critical_incidents' => $criticalCount,
                    'warnings' => $warningCount
                ],
                'performance_summary' => [
                    'average_response_time_ms' => round($avgResponseTime, 2),
                    'average_cpu_usage_percent' => round($avgCpuUsage, 2),
                    'average_memory_usage_mb' => round($avgMemoryUsage, 2),
                    'peak_throughput' => $performanceMetrics->max('throughput_requests_per_second')
                ],
                'component_health' => [
                    'database' => [
                        'status' => $healthChecks->first()?->database_status ?? 'unknown',
                        'statistics' => $dbStats
                    ],
                    'cache' => [
                        'status' => $healthChecks->first()?->cache_status ?? 'unknown',
                        'hit_rate' => $this->getCacheHitRate()
                    ],
                    'api' => [
                        'status' => $healthChecks->first()?->api_status ?? 'unknown',
                        'statistics' => $apiStats
                    ],
                    'queue' => [
                        'status' => $healthChecks->first()?->queue_status ?? 'unknown',
                        'pending_jobs' => $this->getPendingJobsCount()
                    ]
                ],
                'uptime' => [
                    'percentage' => $this->calculateUptime(),
                    'last_incident' => $this->getLastIncident()
                ],
                'recommendations' => $this->generateRecommendations($healthChecks, $performanceMetrics)
            ];

        } catch (Exception $e) {
            Log::error("Health report generation failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Check database health
     * 
     * @return array
     */
    private function checkDatabaseHealth()
    {
        try {
            $start = microtime(true);
            DB::connection()->getPdo();
            $responseTime = (microtime(true) - $start) * 1000;

            // Test query
            $userCount = DB::table('users')->count();

            return [
                'status' => $responseTime < 100 ? 'healthy' : 'warning',
                'response_time_ms' => round($responseTime, 2),
                'connection' => 'active',
                'test_query_result' => $userCount
            ];
        } catch (Exception $e) {
            return [
                'status' => 'critical',
                'error' => $e->getMessage(),
                'connection' => 'failed'
            ];
        }
    }

    /**
     * Check cache health
     * 
     * @return array
     */
    private function checkCacheHealth()
    {
        try {
            $testKey = 'health_check_' . time();
            $testValue = 'test_value';

            Cache::put($testKey, $testValue, 60);
            $retrieved = Cache::get($testKey);
            Cache::forget($testKey);

            $working = $retrieved === $testValue;

            return [
                'status' => $working ? 'healthy' : 'warning',
                'read_write' => $working ? 'functional' : 'failed'
            ];
        } catch (Exception $e) {
            return [
                'status' => 'critical',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Check API health
     * 
     * @return array
     */
    private function checkApiHealth()
    {
        try {
            // Check recent API response times
            $recentMetrics = SystemPerformance::where('recorded_at', '>=', now()->subMinutes(5))
                ->avg('response_time_ms');

            $status = 'healthy';
            if ($recentMetrics > 500) {
                $status = 'critical';
            } elseif ($recentMetrics > 200) {
                $status = 'warning';
            }

            return [
                'status' => $status,
                'average_response_time' => round($recentMetrics, 2)
            ];
        } catch (Exception $e) {
            return [
                'status' => 'unknown',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Check queue health
     * 
     * @return array
     */
    private function checkQueueHealth()
    {
        try {
            $failedJobs = DB::table('failed_jobs')->count();
            $pendingJobs = DB::table('jobs')->count();

            $status = 'healthy';
            if ($failedJobs > 10 || $pendingJobs > 100) {
                $status = 'warning';
            }
            if ($failedJobs > 50 || $pendingJobs > 500) {
                $status = 'critical';
            }

            return [
                'status' => $status,
                'pending_jobs' => $pendingJobs,
                'failed_jobs' => $failedJobs
            ];
        } catch (Exception $e) {
            return [
                'status' => 'unknown',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Calculate overall health score
     * 
     * @param array $componentStatuses
     * @return float
     */
    private function calculateHealthScore(array $componentStatuses)
    {
        $scores = array_map(function($component) {
            return match($component['status']) {
                'healthy' => 100,
                'warning' => 60,
                'critical' => 20,
                default => 50
            };
        }, $componentStatuses);

        return round(array_sum($scores) / count($scores), 2);
    }

    /**
     * Determine overall status from health score
     * 
     * @param float $healthScore
     * @return string
     */
    private function determineOverallStatus(float $healthScore)
    {
        if ($healthScore >= 80) {
            return 'healthy';
        } elseif ($healthScore >= 50) {
            return 'warning';
        } else {
            return 'critical';
        }
    }

    /**
     * Calculate system error rate
     * 
     * @return float
     */
    private function calculateErrorRate()
    {
        try {
            // This would track actual error logs in production
            $totalRequests = 1000; // Placeholder
            $errorRequests = 5; // Placeholder

            return round(($errorRequests / $totalRequests) * 100, 2);
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Calculate system uptime percentage
     * 
     * @return float
     */
    private function calculateUptime()
    {
        try {
            $totalTime = 720; // Last 30 days in hours
            $downtime = SystemHealth::where('health_status', 'critical')
                ->where('checked_at', '>=', now()->subDays(30))
                ->count() * 0.5; // Assuming 30min per check

            return round((($totalTime - $downtime) / $totalTime) * 100, 2);
        } catch (Exception $e) {
            return 99.9;
        }
    }

    /**
     * Get last system incident
     * 
     * @return string|null
     */
    private function getLastIncident()
    {
        $lastCritical = SystemHealth::where('health_status', 'critical')
            ->orderBy('checked_at', 'desc')
            ->first();

        return $lastCritical?->checked_at?->toISOString();
    }

    /**
     * Send health alert notification
     * 
     * @param SystemHealth $health
     */
    private function sendHealthAlert(SystemHealth $health)
    {
        try {
            $notificationService = app(NotificationService::class);
            
            $notificationService->sendNotification([
                'type' => 'system_health_alert',
                'title' => 'Critical System Health Alert',
                'message' => "System health is critical. Health score: {$health->health_score}. Immediate attention required.",
                'priority' => 'critical',
                'channels' => ['in_app', 'email'],
                'recipient_roles' => ['admin'],
                'additional_data' => [
                    'health_score' => $health->health_score,
                    'database_status' => $health->database_status,
                    'cache_status' => $health->cache_status,
                    'api_status' => $health->api_status,
                    'queue_status' => $health->queue_status
                ]
            ]);
        } catch (Exception $e) {
            Log::error("Failed to send health alert: " . $e->getMessage());
        }
    }

    /**
     * Gather performance metrics
     * 
     * @return array
     */
    private function gatherPerformanceMetrics()
    {
        return [
            'metric_name' => 'system_performance',
            'metric_value' => 100,
            'response_time_ms' => $this->getAverageResponseTime(),
            'throughput' => $this->calculateThroughput(),
            'cpu_usage' => $this->getCpuUsage(),
            'memory_usage' => $this->getMemoryUsage(),
            'disk_usage' => $this->getDiskUsage(),
            'active_connections' => $this->getActiveConnections()
        ];
    }

    /**
     * Get average API response time
     * 
     * @return float
     */
    private function getAverageResponseTime()
    {
        return SystemPerformance::where('recorded_at', '>=', now()->subMinutes(5))
            ->avg('response_time_ms') ?? 150;
    }

    /**
     * Calculate throughput (requests per second)
     * 
     * @return float
     */
    private function calculateThroughput()
    {
        // Placeholder - would use actual request logs
        return 25.5;
    }

    /**
     * Get CPU usage percentage
     * 
     * @return float
     */
    private function getCpuUsage()
    {
        // Placeholder - would use system monitoring tools
        return 45.2;
    }

    /**
     * Get memory usage in MB
     * 
     * @return float
     */
    private function getMemoryUsage()
    {
        return round(memory_get_usage(true) / 1024 / 1024, 2);
    }

    /**
     * Get disk usage percentage
     * 
     * @return float
     */
    private function getDiskUsage()
    {
        // Placeholder - would check actual disk usage
        return 65.3;
    }

    /**
     * Get active database connections
     * 
     * @return int
     */
    private function getActiveConnections()
    {
        try {
            return DB::select("SELECT count(*) as count FROM pg_stat_activity")[0]->count ?? 5;
        } catch (Exception $e) {
            return 5;
        }
    }

    /**
     * Get database statistics
     * 
     * @return array
     */
    private function getDatabaseStatistics()
    {
        try {
            return [
                'total_tables' => count(DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'")),
                'database_size' => '150 MB', // Placeholder
                'active_connections' => $this->getActiveConnections(),
                'query_performance' => 'optimal'
            ];
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get API statistics
     * 
     * @param int $hours
     * @return array
     */
    private function getApiStatistics(int $hours)
    {
        $startTime = now()->subHours($hours);
        
        $metrics = SystemPerformance::where('recorded_at', '>=', $startTime)->get();

        return [
            'total_requests' => $metrics->count() * 100, // Placeholder multiplier
            'average_response_time' => round($metrics->avg('response_time_ms'), 2),
            'slowest_response' => round($metrics->max('response_time_ms'), 2),
            'fastest_response' => round($metrics->min('response_time_ms'), 2),
            'error_rate' => $this->calculateErrorRate()
        ];
    }

    /**
     * Get cache hit rate
     * 
     * @return float
     */
    private function getCacheHitRate()
    {
        // Placeholder - would track actual cache statistics
        return 82.5;
    }

    /**
     * Get pending jobs count
     * 
     * @return int
     */
    private function getPendingJobsCount()
    {
        try {
            return DB::table('jobs')->count();
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Generate system recommendations
     * 
     * @param $healthChecks
     * @param $performanceMetrics
     * @return array
     */
    private function generateRecommendations($healthChecks, $performanceMetrics)
    {
        $recommendations = [];

        // Check response times
        $avgResponseTime = $performanceMetrics->avg('response_time_ms');
        if ($avgResponseTime > 200) {
            $recommendations[] = "API response times are elevated ({$avgResponseTime}ms avg). Consider optimizing database queries or implementing caching.";
        }

        // Check memory usage
        $avgMemory = $performanceMetrics->avg('memory_usage_mb');
        if ($avgMemory > 400) {
            $recommendations[] = "Memory usage is high ({$avgMemory}MB avg). Consider increasing server resources or optimizing memory-intensive operations.";
        }

        // Check error rate
        $errorRate = $this->calculateErrorRate();
        if ($errorRate > 1) {
            $recommendations[] = "Error rate is elevated ({$errorRate}%). Review error logs and implement fixes for common failures.";
        }

        // Check health incidents
        $criticalCount = $healthChecks->where('health_status', 'critical')->count();
        if ($criticalCount > 0) {
            $recommendations[] = "System experienced {$criticalCount} critical health incidents. Investigate and resolve underlying issues.";
        }

        if (empty($recommendations)) {
            $recommendations[] = "System is performing optimally. Continue monitoring for any changes.";
        }

        return $recommendations;
    }
}