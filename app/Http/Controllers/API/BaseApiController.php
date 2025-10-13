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
 * FIXED:
 * - Removed unnecessary function_exists('auth') checks
 * - All authentication calls are Sanctum-safe
 * - logActivity() and userCan() cleaned and hardened
 */
abstract class BaseApiController extends Controller
{
    use ApiResponseTrait;

    protected int $perPage = 15;
    protected int $maxPerPage = 100;
    protected int $cacheDuration = 60;

    protected array $commonRules = [
        'page' => 'integer|min:1',
        'per_page' => 'integer|min:1|max:100',
        'sort' => 'string|max:50',
        'order' => 'string|in:asc,desc',
        'search' => 'string|max:255'
    ];

    /**
     * Safe activity logging.
     */
    protected function logActivity(string $action, array $data = [], string $level = 'info'): void
    {
        try {
            $userId = null;
            $userEmail = null;

            $user = $this->currentUser();
            if ($user) {
                $userId = $user->id ?? null;
                $userEmail = $user->email ?? null;
            }

            $request = request();
            $requestInfo = [
                'ip' => $request->ip() ?? 'unknown',
                'user_agent' => $request->userAgent() ?? 'unknown',
                'method' => $request->method() ?? 'unknown',
                'url' => $request->url() ?? 'unknown',
                'endpoint' => $request->path() ?? 'unknown'
            ];

            $logData = array_merge([
                'action' => $action,
                'user_id' => $userId,
                'user_email' => $userEmail,
                'timestamp' => now()->toISOString(),
                'system' => 'Project Kalinga API'
            ], $requestInfo, $data);

            $this->safeLog($level, "API Activity: {$action}", $logData);
        } catch (\Throwable $e) {
            error_log("Kalinga API: Failed to log activity '{$action}' - " . $e->getMessage());
        }
    }

    /**
     * Pagination handling.
     */
    protected function getPaginationParams(Request $request): array
    {
        try {
            return [
                'per_page' => min((int)$request->get('per_page', $this->perPage), $this->maxPerPage),
                'page' => max(1, (int)$request->get('page', 1)),
                'sort' => $request->get('sort', 'id'),
                'order' => in_array($request->get('order'), ['asc', 'desc']) ? $request->get('order') : 'desc'
            ];
        } catch (\Throwable) {
            return [
                'per_page' => $this->perPage,
                'page' => 1,
                'sort' => 'id',
                'order' => 'desc'
            ];
        }
    }

    /**
 * Apply common filters (search, sort, etc.) to a query.
 */
protected function applyFilters($query, Request $request, array $searchableColumns = ['name', 'email']): mixed
{
    try {
        // 🔍 Search term
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search, $searchableColumns) {
                foreach ($searchableColumns as $column) {
                    $q->orWhere($column, 'ILIKE', "%{$search}%");
                }
            });
        }

        // 🧭 Sorting
        $sort = $request->get('sort', 'id');
        $order = $request->get('order', 'desc');
        if (in_array($order, ['asc', 'desc'])) {
            $query->orderBy($sort, $order);
        }

        return $query;
    } catch (\Throwable $e) {
        $this->safeLog('warning', 'applyFilters failed', [
            'error' => $e->getMessage(),
            'stack' => $e->getTraceAsString()
        ]);
        return $query;
    }
}

/**
 * Load model relationships safely (for show and index endpoints).
 */
protected function getResourceWithRelations($modelOrQuery, array $relations = []): mixed
{
    try {
        if (empty($relations)) {
            // Default relations if none are passed
            $relations = ['roles', 'permissions', 'profile'];
        }

        if ($modelOrQuery instanceof \Illuminate\Database\Eloquent\Model) {
            // Single model (like show)
            $modelOrQuery->loadMissing($relations);
            return $modelOrQuery;
        }

        if ($modelOrQuery instanceof \Illuminate\Database\Eloquent\Builder ||
            $modelOrQuery instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
            // Query builder (like index)
            return $modelOrQuery->with($relations);
        }

        return $modelOrQuery;
    } catch (\Throwable $e) {
        $this->safeLog('warning', 'getResourceWithRelations failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return $modelOrQuery;
    }
}



    /**
     * Retrieve authenticated user safely (Sanctum guard only).
     */
    protected function currentUser()
    {
        try {
            return auth('sanctum')->user();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Retrieve authenticated user ID safely.
     */
    protected function currentUserId(): ?int
    {
        try {
            return auth('sanctum')->id();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Permission check (Spatie + Sanctum safe).
     */
    protected function userCan(string $permission, $resource = null): bool
    {
        try {
            $user = $this->currentUser();
            if (!$user) {
                return false;
            }

            if (method_exists($user, 'hasPermissionTo')) {
                return $user->hasPermissionTo($permission, 'sanctum');
            }

            if (method_exists($user, 'can')) {
                return $user->can($permission, $resource);
            }

            return false;
        } catch (\Throwable $e) {
            $this->safeLog('warning', 'Permission check failed', [
                'permission' => $permission,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Handle exceptions safely and return proper JSON response.
     */
    protected function handleException(\Exception $e, string $action = 'operation')
    {
        $this->logActivity("Error in {$action}", [
            'error_type' => get_class($e),
            'message' => $e->getMessage(),
            'line' => $e->getLine(),
            'file' => basename($e->getFile())
        ], 'error');

        if ($e instanceof ModelNotFoundException) {
            return $this->notFoundResponse();
        }

        if ($e instanceof ValidationException) {
            return $this->validationErrorResponse($e->errors());
        }

        if ($e instanceof AuthorizationException) {
            return $this->forbiddenResponse($e->getMessage());
        }

        return $this->serverErrorResponse(
            "An error occurred during {$action}",
            app()->environment('local') ? [
                'message' => $e->getMessage(),
                'file' => basename($e->getFile()),
                'line' => $e->getLine()
            ] : null
        );
    }

    /**
     * Safe cache operations.
     */
    protected function getCached(string $key, callable $callback, int $duration = null)
    {
        try {
            $cacheDuration = $duration ?? $this->cacheDuration;
            return Cache::remember($key, now()->addMinutes($cacheDuration), $callback);
        } catch (\Throwable $e) {
            $this->safeLog('error', 'Cache failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return $callback();
        }
    }

    /**
     * Simple DB health check.
     */
    protected function isDatabaseHealthy(): bool
    {
        try {
            DB::select('SELECT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Get current system load.
     */
    protected function getSystemLoad(): string
    {
        try {
            $active = DB::table('requests')->where('status', 'active')->count();
            $available = DB::table('responders')->where('status', 'available')->count();
            if ($available === 0) return 'unknown';

            $load = $active / $available;
            return match (true) {
                $load >= 0.9 => 'critical',
                $load >= 0.7 => 'high',
                $load >= 0.5 => 'medium',
                default => 'low',
            };
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    abstract protected function getModel(): string;
}

