<?php

namespace App\Providers;

use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        
        // Project Kalinga Events
        \App\Events\RequestEntryCreated::class => [
            \App\Listeners\SendRequestNotification::class,
        ],
        
        \App\Events\ResourceThresholdBreached::class => [
            \App\Listeners\TriggerResourceAlert::class,
        ],
        
        \App\Events\AllocationCompleted::class => [
            \App\Listeners\LogAllocationEvent::class,
        ],
        
        \App\Events\SensorReadingReceived::class => [
            \App\Listeners\ProcessSensorData::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}