<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Base API Controller for Project Kalinga
 * 
 * CRITICAL FIXES IMPLEMENTED:
 * 1. SAFE logActivity() - never crashes when user not authenticated
 * 2. NULL-SAFE user authentication checks
 * 3. Comprehensive exception handling
 * 4. Safe caching and database operations
 * 5. Emergency system methods that never fail
 */
abstract class BaseApiController extends Controller
{
    use ApiResponseTrait;

    /**
     * Items per page for pagination
     */
    protected int $perPage = 15;
    protected int $maxPerPage = 100;
    protected int $cacheDuration = 60;

    /**
     * Common validation rules - SAFE defaults
     */
    protected array $commonRules = [
        'page' => 'integer|min:1',
        'per_page' => 'integer|min:1|max:100',
        'sort' => 'string|max:50',
        'order' => 'string|in:asc,desc',
        'search' => 'string|max:255'
    ];

    /**
     * SAFE Activity Logging - NEVER crashes application
     * 
     * FIXES: Previous crashes when auth()->user() was null
     * NOW: Always works, with or without authenticated user
     */
    protected function logActivity(string $action, array $data = [], string $level = 'info'): void
    {
        try {
            // SAFE user ID extraction - never throws exception
            $userId = null;
            $userEmail = null;
            
            try {
                // Multiple safety checks for authentication
                if (function_exists('auth') && auth()->check() && auth()->user()) {
                    $user = auth()->user();
                    $userId = $user->id ?? null;
                    $userEmail = $user->email ?? null;
                }
            } catch (\Exception $authException) {
                // Ignore auth errors - log will proceed without user info
                $userId = null;
                $userEmail = null;
            }

            // Safe request information extraction
            $requestInfo = [];
            try {
                $request = request();
                $requestInfo = [
                    'ip' => $request->ip() ?? 'unknown',
                    'user_agent' => $request->userAgent() ?? 'unknown',
                    'method' => $request->method() ?? 'unknown',
                    'url' => $request->url() ?? 'unknown',
                    'endpoint' => $request->path() ?? 'unknown'
                ];
            } catch (\Exception $requestException) {
                // Use defaults if request info fails
                $requestInfo = [
                    'ip' => 'unknown',
                    'user_agent' => 'unknown',
                    'method' => 'unknown',
                    'url' => 'unknown',
                    'endpoint' => 'unknown'
                ];
            }

            // Prepare safe log data
            $logData = array_merge([
                'action' => $action,
                'user_id' => $userId,
                'user_email' => $userEmail,
                'timestamp' => now()->toISOString(),
                'system' => 'Project Kalinga API'
            ], $requestInfo, $data);

            // SAFE logging with fallbacks
            $this->safeLog($level, "API Activity: {$action}", $logData);

        } catch (\Exception $e) {
            // Ultimate fallback - never let logging crash the app
            try {
                error_log("Kalinga API: Failed to log activity '{$action}' - " . $e->getMessage());
            } catch (\Exception $fallbackError) {
                // Silent failure - better than crashing
            }
        }
    }

    /**
     * SAFE Pagination Parameters - NULL-SAFE extraction
     */
    protected function getPaginationParams(Request $request): array
    {
        try {
            return [
                'per_page' => min(
                    (int) $request->get('per_page', $this->perPage),
                    $this->maxPerPage
                ),
                'page' => max(1, (int) $request->get('page', 1)),
                'sort' => $request->get('sort', 'id'),
                'order' => in_array($request->get('order'), ['asc', 'desc']) 
                    ? $request->get('order') 
                    : 'desc'
            ];
        } catch (\Exception $e) {
            // Safe fallback values
            return [
                'per_page' => $this->perPage,
                'page' => 1,
                'sort' => 'id',
                'order' => 'desc'
            ];
        }
    }

    /**
     * SAFE Search Parameters - Never throws exceptions
     */
    protected function getSearchParams(Request $request): array
    {
        try {
            return [
                'search' => $request->get('search'),
                'filters' => is_array($request->get('filters')) ? $request->get('filters') : [],
                'date_from' => $request->get('date_from'),
                'date_to' => $request->get('date_to')
            ];
        } catch (\Exception $e) {
            return [
                'search' => null,
                'filters' => [],
                'date_from' => null,
                'date_to' => null
            ];
        }
    }

    /**
     * SAFE Query Filter Application - NULL-SAFE
     */
    protected function applyFilters($query, Request $request, array $searchable = [])
    {
        try {
            // Safe search functionality
            if ($search = $request->get('search')) {
                $query->where(function($q) use ($search, $searchable) {
                    foreach ($searchable as $field) {
                        try {
                            if (str_contains($field, '.')) {
                                // Handle relationship searches safely
                                $parts = explode('.', $field, 2);
                                if (count($parts) === 2) {
                                    [$relation, $column] = $parts;
                                    $q->orWhereHas($relation, function($subQ) use ($column, $search) {
                                        $subQ->where($column, 'ILIKE', "%{$search}%");
                                    });
                                }
                            } else {
                                $q->orWhere($field, 'ILIKE', "%{$search}%");
                            }
                        } catch (\Exception $fieldException) {
                            // Skip problematic fields, continue with others
                            continue;
                        }
                    }
                });
            }

            // Safe date range filters
            if ($dateFrom = $request->get('date_from')) {
                try {
                    $query->whereDate('created_at', '>=', $dateFrom);
                } catch (\Exception $e) {
                    // Skip invalid date filter
                }
            }
            
            if ($dateTo = $request->get('date_to')) {
                try {
                    $query->whereDate('created_at', '<=', $dateTo);
                } catch (\Exception $e) {
                    // Skip invalid date filter
                }
            }

            // Safe status filter
            if ($status = $request->get('status')) {
                try {
                    $query->where('status', $status);
                } catch (\Exception $e) {
                    // Skip invalid status filter
                }
            }

            // Safe sorting
            $sort = $request->get('sort', 'id');
            $order = $request->get('order', 'desc');
            
            try {
                if (str_contains($sort, '.')) {
                    // Handle relationship sorting safely
                    $parts = explode('.', $sort, 2);
                    if (count($parts) === 2) {
                        [$relation, $column] = $parts;
                        $query->orderBy($column, $order);
                    }
                } else {
                    $query->orderBy($sort, $order);
                }
            } catch (\Exception $sortException) {
                // Fallback to default sorting
                $query->orderBy('id', 'desc');
            }

            return $query;
        } catch (\Exception $e) {
            // Return unmodified query if filtering fails
            return $query;
        }
    }

    /**
     * SAFE Exception Handling - Central error processing
     * 
     * FIXES: All controller exception handling issues
     */
    protected function handleException(\Exception $e, string $action = 'operation'): \Illuminate\Http\JsonResponse
    {
        try {
            // Log the error safely
            $this->logActivity("Error in {$action}", [
                'error_type' => get_class($e),
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 'error');

            // Handle specific exception types
            if ($e instanceof ModelNotFoundException) {
                return $this->notFoundResponse();
            }

            if ($e instanceof ValidationException) {
                return $this->validationErrorResponse($e->errors());
            }

            if ($e instanceof AuthorizationException) {
                return $this->forbiddenResponse($e->getMessage());
            }

            // Database exceptions
            if ($e instanceof \Illuminate\Database\QueryException) {
                return $this->serverErrorResponse(
                    'Database error occurred',
                    app()->environment('local') ? $e->getMessage() : null
                );
            }

            // Generic server error with safe details
            $details = null;
            if (app()->environment('local', 'testing')) {
                $details = [
                    'message' => $e->getMessage(),
                    'file' => basename($e->getFile()),
                    'line' => $e->getLine()
                ];
            }

            return $this->serverErrorResponse(
                "An error occurred during {$action}",
                $details
            );

        } catch (\Exception $handlingException) {
            // Ultimate fallback if exception handling itself fails
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'CRITICAL_ERROR',
                    'message' => 'A critical system error occurred',
                    'http_code' => 500
                ],
                'meta' => [
                    'timestamp' => now()->toISOString()
                ]
            ], 500);
        }
    }

    /**
     * SAFE User Permission Check - NULL-SAFE authentication
     * 
     * FIXES: Auth crashes when checking permissions
     */
    protected function userCan(string $permission, $resource = null): bool
    {
        try {
            // Multiple safety checks
            if (!function_exists('auth')) {
                return false;
            }

            if (!auth()->check()) {
                return false;
            }

            $user = auth()->user();
            if (!$user) {
                return false;
            }

            // Check if user has the hasPermissionTo method (Spatie permissions)
            if (method_exists($user, 'hasPermissionTo')) {
                return $user->hasPermissionTo($permission);
            }

            // Fallback permission check
            if (method_exists($user, 'can')) {
                return $user->can($permission, $resource);
            }

            // Default deny if no permission system available
            return false;

        } catch (\Exception $e) {
            // Safe fallback - deny permission if check fails
            $this->safeLog('warning', 'Permission check failed', [
                'permission' => $permission,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * SAFE Resource Retrieval with Relationships
     */
    protected function getResourceWithRelations(int $id, array $relations = [])
    {
        try {
            $modelClass = $this->getModel();
            $query = $modelClass::query();
            
            if (!empty($relations)) {
                $query->with($relations);
            }
            
            return $query->findOrFail($id);
        } catch (\Exception $e) {
            throw new ModelNotFoundException("Resource not found");
        }
    }

    /**
     * SAFE Cache Operations - Never crash
     */
    protected function getCached(string $key, callable $callback, int $duration = null)
    {
        try {
            $cacheDuration = $duration ?? $this->cacheDuration;
            return Cache::remember($key, now()->addMinutes($cacheDuration), $callback);
        } catch (\Exception $e) {
            // If caching fails, execute callback directly
            try {
                return $callback();
            } catch (\Exception $callbackException) {
                $this->safeLog('error', 'Cache and callback both failed', [
                    'key' => $key,
                    'cache_error' => $e->getMessage(),
                    'callback_error' => $callbackException->getMessage()
                ]);
                return null;
            }
        }
    }

    /**
     * SAFE Database Health Check
     */
    protected function isDatabaseHealthy(): bool
    {
        try {
            DB::select('SELECT 1');
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * SAFE Current User - Never returns null unsafely
     */
    protected function getCurrentUser()
    {
        try {
            return auth()->check() ? auth()->user() : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * SAFE Current User ID
     */
    protected function getCurrentUserId(): ?int
    {
        try {
            $user = $this->getCurrentUser();
            return $user ? $user->id : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Emergency System Methods - ALWAYS work
     */
    
    /**
     * Check if request is critical/emergency - SAFE
     */
    protected function isCriticalRequest(Request $request): bool
    {
        try {
            $criticalKeywords = ['critical', 'emergency', 'urgent', 'immediate'];
            $severity = strtolower($request->get('severity_level', ''));
            $priority = (int) $request->get('priority_level', 0);
            
            return in_array($severity, $criticalKeywords) || $priority >= 4;
        } catch (\Exception $e) {
            return false; // Safe default
        }
    }

    /**
     * Get current system load status - SAFE
     */
    protected function getSystemLoad(): string
    {
        try {
            // Safe database queries with fallbacks
            $activeRequests = 0;
            $availableResponders = 0;
            
            try {
                $activeRequests = DB::table('requests')
                    ->where('status', 'active')
                    ->count();
            } catch (\Exception $e) {
                // Use default if query fails
            }
            
            try {
                $availableResponders = DB::table('responders')
                    ->where('status', 'available')
                    ->count();
            } catch (\Exception $e) {
                // Use default if query fails
            }
            
            if ($availableResponders === 0) {
                return 'unknown';
            }
            
            $load = $activeRequests / $availableResponders;
            
            return match(true) {
                $load >= 0.9 => 'critical',
                $load >= 0.7 => 'high',
                $load >= 0.5 => 'medium',
                default => 'low'
            };
        } catch (\Exception $e) {
            return 'unknown';
        }
    }

    /**
     * Send critical alert - SAFE notification
     */
    protected function sendCriticalAlert(array $data): void
    {
        try {
            $this->safeLog('critical', 'CRITICAL SYSTEM ALERT', $data);
            
            // In production, this would trigger:
            // - SMS alerts to administrators
            // - Email notifications
            // - Push notifications
            // - Emergency response protocols
            
        } catch (\Exception $e) {
            // Even if alerting fails, log the attempt
            try {
                error_log('CRITICAL: Alert system failed - ' . json_encode($data));
            } catch (\Exception $fallback) {
                // Silent failure for alert system
            }
        }
    }

    /**
     * Abstract methods that child controllers must implement
     */
    abstract protected function getModel(): string;
    
    /**
     * Override in child controllers for specific validation rules
     */
    protected function getValidationRules(): array
    {
        return [];
    }

    /**
     * Override in child controllers for searchable fields
     */
    protected function getSearchableFields(): array
    {
        return [];
    }

    /**
     * Override in child controllers for default relationships
     */
    protected function getDefaultRelations(): array
    {
        return [];
    }
}