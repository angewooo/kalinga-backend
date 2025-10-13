<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateApiToken
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // For demo purposes, skip token validation
        // In production, implement Laravel Sanctum or Passport
        
        // Uncomment for production with Sanctum:
        /*
        $token = $request->bearerToken();
        
        if (!$token) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'TOKEN_MISSING',
                    'message' => 'API token is required',
                ],
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'version' => 'v1'
                ]
            ], 401);
        }

        // Validate token with Sanctum
        $user = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'TOKEN_INVALID',
                    'message' => 'Invalid or expired API token',
                ],
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'version' => 'v1'
                ]
            ], 401);
        }

        // Set authenticated user
        $request->setUserResolver(function () use ($user) {
            return $user->tokenable;
        });
        */

        return $next($request);
    }
}