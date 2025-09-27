<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Base API Controller for Project Kalinga
 * Provides common functionality for all API controllers
 */
abstract class BaseApiController extends Controller
{
    use ApiResponseTrait, AuthorizesRequests, ValidatesRequests;

    /**
     * Number of items per page for pagination
     */
    protected int $perPage = 15;

    /**
     * Maximum items per page allowed
     */
    protected int $maxPerPage = 100;

    /**
     * Default cache duration in minutes
     */
    protected int $cacheDuration = 60;

    /**
     * Common validation rules
     */
    protected array $commonRules = [
        'page' => 'integer|min:1',
        'per_page' => 'integer|min:1|max:100',
        'sort' => 'string|in:id,name,created_at,updated_at',
        'order' => 'string|in:asc,desc',
        'search' => 'string|max:255'
    ];

    /**
     * Get pagination parameters from request
     *
     * @param Request $request
     * @return array
     */
    protected function getPaginationParams(Request $request): array
    {
        return [
            'per_page' => min($request->get('per_page', $this->perPage), $this->maxPerPage),
            'page' => $request->get('page', 1),
            'sort' => $request->get('sort', 'id'),
            'order' => $request->get('order', 'desc')
        ];
    }

    /**
     * Get search parameters from request
     *
     * @param Request $request
     * @return array
     */
    protected function getSearchParams(Request $request): array
    {
        return [
            'search' => $request->get('search'),
            'filters' => $request->get('filters', []),
            'date_from' => $request->get('date_from'),
            'date_to' => $request->get('date_to')
        ];
    }

    /**
     * Apply common query filters
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param Request $request
     * @param array $searchable
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function applyFilters($query, Request $request, array $searchable = [])
    {
        // Search functionality
        if ($search = $request->get('search')) {
            $query->where(function($q) use ($search, $searchable) {
                foreach ($searchable as $field) {
                    if (str_contains($field, '.')) {
                        // Handle relationship searches
                        [$relation, $field] = explode('.', $field, 2);
                        $q->orWhereHas($relation, function($subQ) use ($field, $search) {
                            $subQ->where($field, 'ILIKE', "%{$search}%");
                        });
                    } else {
                        $q->orWhere($field, 'ILIKE', "%{$search}%");
                    }
                }
            });
        }

        // Date range filters
        if ($dateFrom = $request->get('date_from')) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        
        if ($dateTo = $request->get('date_to')) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        // Status filter (common across many models)
        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        // Sorting
        $sort = $request->get('sort', 'id');
        $order = $request->get('order', 'desc');
        
        if (str_contains($sort, '.')) {
            // Handle relationship sorting
            [$relation, $field] = explode('.', $sort, 2);
            $query->join($relation, function($join) use ($relation) {
                $join->on("{$relation}.id", '=', $this->getModel()->getTable() . ".{$relation}_id");
            })->orderBy("{$relation}.{$field}", $order);
        } else {
            $query->orderBy($sort, $order);
        }

        return $query;
    }

    /**
     * Cache key generator for consistent caching
     *
     * @param string $prefix
     * @param array $params
     * @return string
     */
    protected function getCacheKey(string $prefix, array $params = []): string
    {
        $key = $prefix;
        
        if (!empty($params)) {
            ksort($params);
            $key .= '_' . md5(serialize($params));
        }
        
        return $key;
    }

    /**
     * Get cached data or execute callback
     *
     * @param string $key
     * @param callable $callback
     * @param int|null $duration
     * @return mixed
     */
    protected function getCached(string $key, callable $callback, int $duration = null)
    {
        $duration = $duration ?? $this->cacheDuration;
        
        return Cache::remember($key, now()->addMinutes($duration), $callback);
    }

    /**
     * Clear cache by pattern
     *
     * @param string $pattern
     * @return void
     */
    protected function clearCache(string $pattern): void
    {
        // Note: This is a simple implementation. In production, consider using Redis with pattern matching
        if (Cache::getDefaultDriver() === 'redis') {
            $keys = Cache::getRedis()->keys("*{$pattern}*");
            foreach ($keys as $key) {
                Cache::forget($key);
            }
        }
    }

    /**
     * Log API activity
     *
     * @param string $action
     * @param array $data
     * @param string $level
     * @return void
     */
    /**
 * Log API activity with maximum safety
 */
protected function logActivity(string $action, array $data = [], string $level = 'info'): void
{
    try {
        // Ultra-safe logging - avoid any potential issues
        $message = "API Action: {$action}";
        
        // Build safe log data
        $logData = [
            'action' => $action,
            'timestamp' => now()->toISOString(),
        ];
        
        // Safely add user ID if available
        try {
            if (auth()->check()) {
                $logData['user_id'] = auth()->id();
            }
        } catch (\Exception $e) {
            // Ignore auth errors
        }
        
        // Safely add request data
        try {
            $request = app('request');
            $logData['ip'] = $request->ip() ?? 'unknown';
            $logData['method'] = $request->method() ?? 'unknown';
            $logData['endpoint'] = $request->fullUrl() ?? 'unknown';
        } catch (\Exception $e) {
            // Ignore request errors
        }
        
        // Add custom data
        if (!empty($data)) {
            $logData['custom_data'] = $data;
        }
        
        // Use basic logging without channels
        \Log::info($message, $logData);
        
    } catch (\Exception $e) {
        // If even basic logging fails, do nothing to avoid loops
        // You can comment this out for debugging:
        // error_log("Fallback log failed: " . $e->getMessage());
    }
}

    /**
     * Handle common exceptions
     *
     * @param \Exception $e
     * @param string $action
     * @return \Illuminate\Http\JsonResponse
     */
    protected function handleException(\Exception $e, string $action = 'operation'): \Illuminate\Http\JsonResponse
    {
        $this->logActivity("Error in {$action}", [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ], 'error');

        // Handle specific exception types
        if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->notFoundResponse();
        }

        if ($e instanceof \Illuminate\Validation\ValidationException) {
            return $this->validationErrorResponse($e->errors());
        }

        if ($e instanceof \Illuminate\Auth\Access\AuthorizationException) {
            return $this->forbiddenResponse($e->getMessage());
        }

        // Generic server error
        return $this->serverErrorResponse(
            "An error occurred during {$action}",
            app()->environment('local') ? [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ] : null
        );
    }

    /**
     * Validate bulk operation data
     *
     * @param array $data
     * @param array $rules
     * @return array
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function validateBulkData(array $data, array $rules): array
    {
        $validated = [];
        
        foreach ($data as $index => $item) {
            $validator = validator($item, $rules);
            
            if ($validator->fails()) {
                throw new \Illuminate\Validation\ValidationException($validator, response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'BULK_VALIDATION_FAILED',
                        'message' => "Validation failed for item at index {$index}",
                        'details' => $validator->errors()
                    ]
                ], 422));
            }
            
            $validated[] = $validator->validated();
        }
        
        return $validated;
    }

    /**
     * Get resource with eager loading
     *
     * @param int $id
     * @param array $relations
     * @return \Illuminate\Database\Eloquent\Model
     */
    protected function getResourceWithRelations(int $id, array $relations = [])
    {
        $query = $this->getModel()->newQuery();
        
        if (!empty($relations)) {
            $query->with($relations);
        }
        
        return $query->findOrFail($id);
    }

    /**
     * Check if user has permission for action
     *
     * @param string $permission
     * @param mixed $resource
     * @return bool
     */
    protected function userCan(string $permission, $resource = null): bool
    {
        $user = auth('sanctum')->user();
        
        if (!$user) {
            return false;
        }
        
        // Check Spatie permission
        if ($user->hasPermissionTo($permission)) {
            return true;
        }
        
        // Additional custom authorization logic can be added here
        
        return false;
    }

    /**
     * Get the model class for this controller
     * Should be implemented by child controllers
     *
     * @return string
     */
    abstract protected function getModel(): string;

    /**
     * Standard CRUD validation rules
     * Should be implemented by child controllers
     *
     * @return array
     */
    abstract protected function getValidationRules(): array;

    /**
     * Get searchable fields for filtering
     * Should be implemented by child controllers
     *
     * @return array
     */
    protected function getSearchableFields(): array
    {
        return ['name', 'description'];
    }

    /**
     * Get default relationships to load
     * Can be overridden by child controllers
     *
     * @return array
     */
    protected function getDefaultRelations(): array
    {
        return [];
    }

    /**
     * Emergency system specific methods
     */

    /**
     * Check if request is critical/emergency
     *
     * @param Request $request
     * @return bool
     */
    protected function isCriticalRequest(Request $request): bool
    {
        $criticalKeywords = ['critical', 'emergency', 'urgent', 'immediate'];
        $severity = strtolower($request->get('severity_level', ''));
        
        return in_array($severity, $criticalKeywords) || 
               $request->get('priority_level', 0) >= 4;
    }

    /**
     * Get current system load status
     *
     * @return string
     */
    protected function getSystemLoad(): string
    {
        // This would integrate with your system monitoring
        $activeRequests = \App\Models\RequestEntry::where('status', 'active')->count();
        $availableResponders = \App\Models\Responder::where('status', 'available')->count();
        
        $load = $availableResponders > 0 ? ($activeRequests / $availableResponders) : 1;
        
        return match(true) {
            $load >= 0.9 => 'critical',
            $load >= 0.7 => 'high',
            $load >= 0.5 => 'medium',
            default => 'low'
        };
    }

    /**
     * Calculate estimated response time based on current system status
     *
     * @param string $severity
     * @param string|null $location
     * @return int Response time in minutes
     */
    protected function calculateEstimatedResponseTime(string $severity, string $location = null): int
    {
        $baseTime = match($severity) {
            'critical' => 5,
            'high' => 15,
            'medium' => 30,
            'low' => 60,
            default => 30
        };
        
        // Factor in current system load
        $systemLoad = $this->getSystemLoad();
        $loadMultiplier = match($systemLoad) {
            'critical' => 2.0,
            'high' => 1.5,
            'medium' => 1.2,
            default => 1.0
        };
        
        return (int) ($baseTime * $loadMultiplier);
    }

    /**
     * Send critical alert notification
     *
     * @param array $data
     * @return void
     */
    protected function sendCriticalAlert(array $data): void
    {
        try {
            // Use default channel if alerts channel is not configured
            $channel = config('logging.channels.alerts') ? 'alerts' : config('logging.default', 'stack');
            
            Log::channel($channel)->critical('Critical System Alert', $data);
            
            // Here you would trigger immediate notifications
            // event(new \App\Events\CriticalAlert($data));
            
        } catch (\Exception $e) {
            // Fallback to basic logging if critical alert fails
            Log::error('Critical alert failed to send', [
                'original_error' => $e->getMessage(),
                'alert_data' => $data
            ]);
        }
    }

    /**
     * Validate coordinates for location-based requests
     *
     * @param float|null $latitude
     * @param float|null $longitude
     * @return bool
     */
    protected function validateCoordinates(?float $latitude, ?float $longitude): bool
    {
        if ($latitude === null || $longitude === null) {
            return false;
        }
        
        return $latitude >= -90 && $latitude <= 90 && 
               $longitude >= -180 && $longitude <= 180;
    }

    /**
     * Calculate distance between two points (Haversine formula)
     *
     * @param float $lat1
     * @param float $lon1
     * @param float $lat2
     * @float $lon2
     * @return float Distance in kilometers
     */
    protected function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371; // km
        
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        
        $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        
        return $earthRadius * $c;
    }
}