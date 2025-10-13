<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\SystemPerformance;

class SystemPerformanceSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            ['metric_name' => 'CPU Usage', 'metric_value' => 68.4, 'benchmark_value' => 75.0, 'hospital_id' => 1, 'measured_at' => now()],
            ['metric_name' => 'Memory Usage', 'metric_value' => 72.1, 'benchmark_value' => 80.0, 'hospital_id' => 1, 'measured_at' => now()],
            ['metric_name' => 'API Latency', 'metric_value' => 140.5, 'benchmark_value' => 200.0, 'hospital_id' => 2, 'measured_at' => now()],
            ['metric_name' => 'Disk Utilization', 'metric_value' => 82.3, 'benchmark_value' => 90.0, 'hospital_id' => 2, 'measured_at' => now()],
            ['metric_name' => 'Network Throughput', 'metric_value' => 95.2, 'benchmark_value' => 100.0, 'hospital_id' => 3, 'measured_at' => now()],
        ];

        foreach ($data as $item) {
            SystemPerformance::create($item);
        }
    }
}
