<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\AllocationAlgorithm;

class AllocationAlgorithmSeeder extends Seeder
{
    public function run()
    {
        $data = [
            ['algorithm_name' => 'Greedy Allocation', 'algorithm_description' => 'Allocates resources based on priority and demand ratio.', 'success_rate' => 95.2, 'average_response_time' => 220, 'is_default' => true],
            ['algorithm_name' => 'Round Robin', 'algorithm_description' => 'Distributes requests evenly among available responders.', 'success_rate' => 90.4, 'average_response_time' => 310, 'is_default' => false],
            ['algorithm_name' => 'AI Adaptive', 'algorithm_description' => 'Uses historical demand patterns for dynamic distribution.', 'success_rate' => 97.5, 'average_response_time' => 250, 'is_default' => false],
            ['algorithm_name' => 'Threshold-Based', 'algorithm_description' => 'Triggers allocation when resources fall below threshold.', 'success_rate' => 89.7, 'average_response_time' => 180, 'is_default' => false],
            ['algorithm_name' => 'Predictive Smart', 'algorithm_description' => 'Forecasts needs based on AI model predictions.', 'success_rate' => 96.8, 'average_response_time' => 260, 'is_default' => false],
        ];

        foreach ($data as $item) {
            AllocationAlgorithm::create($item);
        }
    }
}
