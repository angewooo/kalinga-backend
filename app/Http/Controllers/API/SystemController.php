<?php

namespace App\Http\Controllers\API;

use App\Models\SystemHealth;
use App\Models\SystemPerformance;
use App\Models\RealTimeSensor;
use App\Models\SensorReading;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

/**
 * System Controller for Project Kalinga
 * Handles system monitoring, health checks, and sensor management
 */
class SystemController extends BaseApiController
{
    /**
     * Get the model class for this controller
     */
    protected function getModel(): string
    {
        return SystemHealth::class;
    }

    /**
     * Get validation rules
     */
    protected function getValidationRules(): array
    {
        return [
            'component_name' => 'required|string|max:50',
            'status' => 'required|string|in:healthy,warning,critical,down',
            'response_time_ms' => 'sometimes|integer|min:0',
            'error_rate' => 'sometimes|numeric|min:0|max:100',
        ];
    }

    /**
     * Get system health status
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function health(Request $request): JsonResponse
    {
        try {
            $healthData = $this->performSystemHealthCheck();

            return $this->systemHealthResponse($healthData);

        } catch (\Exception $e) {
            return $this->handleException($e, 'checking system health');
        }
    }

    /**
     * Get system status overview
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function status(Request $request): JsonResponse
    {
        try {
            $status = [
                'system_uptime' => $this->getSystemUptime(),
                'api_version' => 'v1.0.0',
                'database_status' => $this->getDatabaseStatus(),
                'cache_status' => $this->getCacheStatus(),
                'active_users' => $this->getActiveUserCount(),
                'system_load' => $this->getSystemLoad(),
                'memory_usage' => $this->getMemoryUsage(),
                'disk_usage' => $this->getDiskUsage(),
                'last_updated' => now()->toISOString()
            ];

            return $this->successResponse($status, 'System status retrieved successfully.');

        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieving system status');
        }
    }

    // Continue with remaining system monitoring methods...
    // The rest follows similar patterns for performance monitoring,
    // sensor management, database operations, etc.
}