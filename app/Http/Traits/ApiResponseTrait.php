<?php

namespace App\Http\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * API Response Trait for Project Kalinga
 * Provides consistent JSON response format across all API endpoints
 */
trait ApiResponseTrait
{
    /**
     * Success response with data
     *
     * @param mixed $data
     * @param string|null $message
     * @param int $code
     * @param array $meta
     * @return JsonResponse
     */
    protected function successResponse($data = null, string $message = null, int $code = 200, array $meta = []): JsonResponse
    {
        $response = [
            'success' => true,
            'data' => $data,
            'meta' => array_merge([
                'timestamp' => now()->toISOString(),
                'version' => 'v1',
                'execution_time' => $this->getExecutionTime()
            ], $meta)
        ];

        if ($message) {
            $response['message'] = $message;
        }

        return response()->json($response, $code);
    }

    /**
     * Success response with pagination
     *
     * @param LengthAwarePaginator $paginator
     * @param string|null $message
     * @return JsonResponse
     */
    protected function paginatedResponse(LengthAwarePaginator $paginator, string $message = null): JsonResponse
    {
        return $this->successResponse(
            $paginator->items(),
            $message,
            200,
            [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                    'has_next' => $paginator->hasMorePages(),
                    'has_prev' => $paginator->currentPage() > 1
                ]
            ]
        );
    }

    /**
     * Error response
     *
     * @param string $message
     * @param int $code
     * @param mixed $details
     * @param string|null $errorCode
     * @return JsonResponse
     */
    protected function errorResponse(string $message, int $code = 400, $details = null, string $errorCode = null): JsonResponse
    {
        $response = [
            'success' => false,
            'error' => [
                'code' => $errorCode ?: $this->getErrorCode($code),
                'message' => $message,
                'http_code' => $code
            ],
            'meta' => [
                'timestamp' => now()->toISOString(),
                'version' => 'v1'
            ]
        ];

        if ($details !== null) {
            $response['error']['details'] = $details;
        }

        return response()->json($response, $code);
    }

    /**
     * Validation error response
     *
     * @param array $errors
     * @param string $message
     * @return JsonResponse
     */
    protected function validationErrorResponse(array $errors, string $message = 'The given data was invalid.'): JsonResponse
    {
        return $this->errorResponse(
            $message,
            422,
            $errors,
            'VALIDATION_FAILED'
        );
    }

    /**
     * Not found error response
     *
     * @param string $resource
     * @return JsonResponse
     */
    protected function notFoundResponse(string $resource = 'Resource'): JsonResponse
    {
        return $this->errorResponse(
            "{$resource} not found.",
            404,
            null,
            'RESOURCE_NOT_FOUND'
        );
    }

    /**
     * Unauthorized response
     *
     * @param string $message
     * @return JsonResponse
     */
    protected function unauthorizedResponse(string $message = 'Unauthorized access.'): JsonResponse
    {
        return $this->errorResponse(
            $message,
            401,
            null,
            'UNAUTHORIZED'
        );
    }

    /**
     * Forbidden response
     *
     * @param string $message
     * @return JsonResponse
     */
    protected function forbiddenResponse(string $message = 'Access forbidden.'): JsonResponse
    {
        return $this->errorResponse(
            $message,
            403,
            null,
            'FORBIDDEN'
        );
    }

    /**
     * Server error response
     *
     * @param string $message
     * @param mixed $details
     * @return JsonResponse
     */
    protected function serverErrorResponse(string $message = 'Internal server error.', $details = null): JsonResponse
    {
        return $this->errorResponse(
            $message,
            500,
            app()->environment('local') ? $details : null,
            'SERVER_ERROR'
        );
    }

    /**
     * Resource created response
     *
     * @param mixed $data
     * @param string $message
     * @return JsonResponse
     */
    protected function createdResponse($data, string $message = 'Resource created successfully.'): JsonResponse
    {
        return $this->successResponse($data, $message, 201);
    }

    /**
     * Resource updated response
     *
     * @param mixed $data
     * @param string $message
     * @return JsonResponse
     */
    protected function updatedResponse($data, string $message = 'Resource updated successfully.'): JsonResponse
    {
        return $this->successResponse($data, $message, 200);
    }

    /**
     * Resource deleted response
     *
     * @param string $message
     * @return JsonResponse
     */
    protected function deletedResponse(string $message = 'Resource deleted successfully.'): JsonResponse
    {
        return $this->successResponse(null, $message, 200);
    }

    /**
     * No content response
     *
     * @return JsonResponse
     */
    protected function noContentResponse(): JsonResponse
    {
        return response()->json(null, 204);
    }

    /**
     * Resource collection response
     *
     * @param mixed $collection
     * @param string $message
     * @param array $meta
     * @return JsonResponse
     */
    protected function collectionResponse($collection, string $message = null, array $meta = []): JsonResponse
    {
        $data = [
            'items' => $collection,
            'count' => is_countable($collection) ? count($collection) : 0
        ];

        return $this->successResponse($data, $message, 200, $meta);
    }

    /**
     * Get error code based on HTTP status
     *
     * @param int $httpCode
     * @return string
     */
    private function getErrorCode(int $httpCode): string
    {
        return match($httpCode) {
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHORIZED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            405 => 'METHOD_NOT_ALLOWED',
            409 => 'CONFLICT',
            422 => 'VALIDATION_FAILED',
            429 => 'TOO_MANY_REQUESTS',
            500 => 'INTERNAL_SERVER_ERROR',
            503 => 'SERVICE_UNAVAILABLE',
            default => 'UNKNOWN_ERROR'
        };
    }

    /**
     * Get execution time (if available)
     *
     * @return string
     */
    private function getExecutionTime(): string
    {
        if (defined('LARAVEL_START')) {
            return round((microtime(true) - LARAVEL_START) * 1000, 2) . 'ms';
        }
        
        return '0ms';
    }

    /**
     * API Response for Emergency System specific responses
     */

    /**
     * Emergency request created response
     *
     * @param mixed $request
     * @param string $severity
     * @return JsonResponse
     */
    protected function emergencyRequestResponse($request, string $severity = 'medium'): JsonResponse
    {
        $message = match($severity) {
            'critical' => 'CRITICAL: Emergency request created and dispatched immediately.',
            'high' => 'HIGH PRIORITY: Emergency request created and processing.',
            'medium' => 'Emergency request created successfully.',
            default => 'Request submitted successfully.'
        };

        return $this->successResponse($request, $message, 201, [
            'severity' => $severity,
            'priority_level' => $this->getSeverityLevel($severity),
            'estimated_response_time' => $this->getEstimatedResponseTime($severity)
        ]);
    }

    /**
     * Resource allocation response
     *
     * @param mixed $allocation
     * @param array $metrics
     * @return JsonResponse
     */
    protected function allocationResponse($allocation, array $metrics = []): JsonResponse
    {
        return $this->successResponse($allocation, 'Resources allocated successfully.', 200, [
            'allocation_metrics' => $metrics,
            'algorithm_used' => $metrics['algorithm'] ?? 'default',
            'confidence_score' => $metrics['confidence'] ?? null,
            'optimization_time' => $metrics['optimization_time'] ?? null
        ]);
    }

    /**
     * Forecast result response
     *
     * @param mixed $forecast
     * @param float $confidence
     * @return JsonResponse
     */
    protected function forecastResponse($forecast, float $confidence = 0.0): JsonResponse
    {
        return $this->successResponse($forecast, 'Forecast generated successfully.', 200, [
            'confidence_level' => round($confidence * 100, 2) . '%',
            'model_accuracy' => $this->getModelAccuracy(),
            'forecast_horizon' => '7 days',
            'last_updated' => now()->toISOString()
        ]);
    }

    /**
     * System health response
     *
     * @param array $healthData
     * @return JsonResponse
     */
    protected function systemHealthResponse(array $healthData): JsonResponse
    {
        $overallStatus = $this->calculateOverallHealth($healthData);
        
        return $this->successResponse($healthData, 'System health check completed.', 200, [
            'overall_status' => $overallStatus,
            'components_checked' => count($healthData),
            'last_check' => now()->toISOString(),
            'response_time_avg' => $this->calculateAverageResponseTime($healthData)
        ]);
    }

    /**
     * Helper methods for emergency system specific responses
     */
    private function getSeverityLevel(string $severity): int
    {
        return match($severity) {
            'critical' => 1,
            'high' => 2,
            'medium' => 3,
            'low' => 4,
            default => 3
        };
    }

    private function getEstimatedResponseTime(string $severity): string
    {
        return match($severity) {
            'critical' => '< 5 minutes',
            'high' => '< 15 minutes',
            'medium' => '< 30 minutes',
            'low' => '< 60 minutes',
            default => 'TBD'
        };
    }

    private function getModelAccuracy(): string
    {
        // This would typically come from your AI model performance metrics
        return '85.3%';
    }

    private function calculateOverallHealth(array $healthData): string
    {
        $totalComponents = count($healthData);
        $healthyComponents = collect($healthData)->where('status', 'healthy')->count();
        
        $healthPercentage = ($healthyComponents / $totalComponents) * 100;
        
        return match(true) {
            $healthPercentage >= 95 => 'excellent',
            $healthPercentage >= 80 => 'good',
            $healthPercentage >= 60 => 'fair',
            default => 'poor'
        };
    }

    private function calculateAverageResponseTime(array $healthData): string
    {
        $avgTime = collect($healthData)->avg('response_time_ms');
        return round($avgTime, 2) . 'ms';
    }
}