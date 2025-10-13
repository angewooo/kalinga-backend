<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Models\SystemHealth;

class CheckSystemHealth
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if system is healthy before processing request
        try {
            // Quick database check
            DB::select('SELECT 1');
            
            // Get latest system health status
            $latestHealth = SystemHealth::latest()->first();
            
            // If system is unhealthy, return maintenance mode response
            if ($latestHealth && $latestHealth->status === 'unhealthy') {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'SYSTEM_MAINTENANCE',
                        'message' => 'System is currently under maintenance',
                        'details' => [
                            'database_status' => $latestHealth->database_status,
                            'cache_status' => $latestHealth->cache_status,
                            'queue_status' => $latestHealth->queue_status,
                        ]
                    ],
                    'meta' => [
                        'timestamp' => now()->toISOString(),
                        'version' => 'v1'
                    ]
                ], 503);
            }
            
        } catch (\Exception $e) {
            // If health check fails, log but allow request (fail open)
            \Log::error('System health check failed: ' . $e->getMessage());
        }

        return $next($request);
    }
}