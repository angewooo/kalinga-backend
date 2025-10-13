<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\SystemHealth;

class SystemHealthSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            ['component_name' => 'Database', 'status' => 'healthy', 'response_time_ms' => 120, 'error_rate' => 0.5, 'last_checked' => now()],
            ['component_name' => 'API Gateway', 'status' => 'healthy', 'response_time_ms' => 90, 'error_rate' => 0.3, 'last_checked' => now()],
            ['component_name' => 'Notification Service', 'status' => 'degraded', 'response_time_ms' => 250, 'error_rate' => 1.5, 'last_checked' => now()],
            ['component_name' => 'Forecast Engine', 'status' => 'healthy', 'response_time_ms' => 110, 'error_rate' => 0.7, 'last_checked' => now()],
            ['component_name' => 'Sensor Module', 'status' => 'offline', 'response_time_ms' => null, 'error_rate' => null, 'last_checked' => now()],
        ];

        foreach ($data as $item) {
            SystemHealth::create($item);
        }
    }
}
