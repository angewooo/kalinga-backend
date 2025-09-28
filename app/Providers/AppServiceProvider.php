<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Request;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register any application services here
    }

    public function boot(): void
    {
        // Boot any application services here
        
        // Define the webhooks rate limiter
        RateLimiter::for('webhooks', function (Request $request) {
            return Limit::perMinute(60); // 60 requests per minute
        });
    }
}