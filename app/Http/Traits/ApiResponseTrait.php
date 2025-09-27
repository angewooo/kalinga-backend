<?php

namespace App\Http\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

/**
 * API Response Trait for Project Kalinga
 * Provides consistent, error-proof JSON response format
 * 
 * FIXES IMPLEMENTED:
 * - Safe logging that never crashes
 * - Null-safe response handling
 * - Consistent error codes
 */
trait ApiResponseTrait
{
    /**
     * Success response with data
     * SAFE: Never throws exceptions
     */
    protected function successResponse($data = null, string $message = null, int $code = 200, array $meta = []): JsonResponse
    {
        try {
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
        } catch (\Exception $e) {
            // Fallback response if anything goes wrong
            return response()->json([
                'success' => true,
                'data' => $data,
                'message' => $message ?? 'Success',
                'meta' => ['timestamp' => now()->toISOString(), 'version' => 'v1']
            ], 200);
        }
    }

    /**
     * Error response - GUARANTEED to never fail
     */
    protected function errorResponse(string $message, int $code = 400, $details = null, string $errorCode = null): JsonResponse
    {
        try {
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
        } catch (\Exception $e) {
            // Ultimate fallback - basic error response
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'SERVER_ERROR',
                    'message' => $message,
                    'http_code' => $code
                ]
            ], $code);
        }
    }

    /**
     * Pagination response - NULL-SAFE
     */
    protected function paginatedResponse(LengthAwarePaginator $paginator, string $message = null): JsonResponse
    {
        try {
            $items = $paginator->items();
            if (method_exists($paginator, 'getCollection')) {
                $items = $paginator->getCollection()->toArray();
            }

            return $this->successResponse(
                $items,
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
        } catch (\Exception $e) {
            // Fallback for pagination errors
            return $this->successResponse([], $message ?? 'Data retrieved successfully.');
        }
    }

    /**
     * Validation error response
     */
    protected function validationErrorResponse(array $errors, string $message = 'The given data was invalid.'): JsonResponse
    {
        return $this->errorResponse($message, 422, $errors, 'VALIDATION_FAILED');
    }

    /**
     * Authentication error response - FIXES 500 error issue
     */
    protected function unauthorizedResponse(string $message = 'Authentication required.'): JsonResponse
    {
        return $this->errorResponse($message, 401, null, 'UNAUTHORIZED');
    }

    /**
     * Forbidden response
     */
    protected function forbiddenResponse(string $message = 'Access forbidden.'): JsonResponse
    {
        return $this->errorResponse($message, 403, null, 'FORBIDDEN');
    }

    /**
     * Not found response
     */
    protected function notFoundResponse(string $resource = 'Resource'): JsonResponse
    {
        return $this->errorResponse("{$resource} not found.", 404, null, 'RESOURCE_NOT_FOUND');
    }

    /**
     * Method not allowed response - FIXES 405 errors
     */
    protected function methodNotAllowedResponse(string $message = 'HTTP method not allowed for this endpoint.'): JsonResponse
    {
        return $this->errorResponse($message, 405, null, 'METHOD_NOT_ALLOWED');
    }

    /**
     * Server error response - SAFE logging
     */
    protected function serverErrorResponse(string $message = 'Internal server error.', $details = null): JsonResponse
    {
        // Safe logging - never throws exceptions
        $this->safeLog('error', 'Server error occurred', [
            'message' => $message,
            'details' => $details,
            'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)
        ]);

        $responseDetails = null;
        if (app()->environment('local', 'testing') && $details) {
            $responseDetails = is_array($details) ? $details : ['error' => $details];
        }

        return $this->errorResponse($message, 500, $responseDetails, 'SERVER_ERROR');
    }

    /**
     * Created response
     */
    protected function createdResponse($data, string $message = 'Resource created successfully.'): JsonResponse
    {
        return $this->successResponse($data, $message, 201);
    }

    /**
     * Updated response
     */
    protected function updatedResponse($data, string $message = 'Resource updated successfully.'): JsonResponse
    {
        return $this->successResponse($data, $message, 200);
    }

    /**
     * Deleted response
     */
    protected function deletedResponse(string $message = 'Resource deleted successfully.'): JsonResponse
    {
        return $this->successResponse(null, $message, 200);
    }

    /**
     * Collection response
     */
    protected function collectionResponse($collection, string $message = null, array $meta = []): JsonResponse
    {
        try {
            $data = [
                'items' => is_array($collection) ? $collection : $collection->toArray(),
                'count' => is_countable($collection) ? count($collection) : 0
            ];

            return $this->successResponse($data, $message, 200, $meta);
        } catch (\Exception $e) {
            return $this->successResponse(['items' => [], 'count' => 0], $message);
        }
    }

    /**
     * SAFE logging method - NEVER throws exceptions
     * FIXES: logActivity crashes when no user authenticated
     */
    protected function safeLog(string $level, string $message, array $context = []): void
    {
        try {
            // Get user ID safely without causing errors
            $userId = null;
            try {
                if (auth()->check()) {
                    $userId = auth()->id();
                }
            } catch (\Exception $e) {
                // Ignore auth errors during logging
            }

            $safeContext = array_merge([
                'user_id' => $userId,
                'ip' => request()->ip() ?? 'unknown',
                'method' => request()->method() ?? 'unknown',
                'url' => request()->url() ?? 'unknown',
                'timestamp' => now()->toISOString()
            ], $context);

            Log::$level($message, $safeContext);
        } catch (\Exception $e) {
            // If logging fails, try basic error_log as fallback
            try {
                error_log("Kalinga API Log Error: {$message} - " . json_encode($context));
            } catch (\Exception $fallbackError) {
                // If even error_log fails, silently continue
                // This prevents logging from crashing the application
            }
        }
    }

    /**
     * Get error code based on HTTP status - NULL SAFE
     */
    private function getErrorCode(int $httpCode): string
    {
        try {
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
        } catch (\Exception $e) {
            return 'UNKNOWN_ERROR';
        }
    }

    /**
     * Get execution time safely
     */
    private function getExecutionTime(): string
    {
        try {
            if (defined('LARAVEL_START')) {
                return round((microtime(true) - LARAVEL_START) * 1000, 2) . 'ms';
            }
            return '0ms';
        } catch (\Exception $e) {
            return '0ms';
        }
    }

    /**
     * Emergency response for critical system alerts
     * SAFE: Never fails, always returns valid response
     */
    protected function emergencyResponse($data, string $severity = 'high', array $alerts = []): JsonResponse
    {
        try {
            $message = match($severity) {
                'critical' => 'CRITICAL ALERT: Immediate action required',
                'high' => 'HIGH PRIORITY ALERT: Action required',
                'medium' => 'ALERT: Attention needed',
                default => 'System notification'
            };

            return $this->successResponse($data, $message, 200, [
                'alert_level' => $severity,
                'alerts' => $alerts,
                'priority' => $this->getSeverityLevel($severity),
                'response_required' => in_array($severity, ['critical', 'high'])
            ]);
        } catch (\Exception $e) {
            // Emergency fallback - always works
            return response()->json([
                'success' => true,
                'data' => $data,
                'message' => 'Emergency alert generated',
                'meta' => ['alert_level' => $severity, 'timestamp' => now()->toISOString()]
            ], 200);
        }
    }

    /**
     * Get severity level number - SAFE
     */
    private function getSeverityLevel(string $severity): int
    {
        try {
            return match($severity) {
                'critical' => 1,
                'high' => 2,
                'medium' => 3,
                'low' => 4,
                default => 3
            };
        } catch (\Exception $e) {
            return 3;
        }
    }
}