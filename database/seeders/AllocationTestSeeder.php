<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\AllocationTest;

class AllocationTestSeeder extends Seeder
{
    public function run()
    {
        $data = [
            ['algorithm_id' => 1, 'test_scenario' => 'High demand surge', 'input_data' => json_encode(['requests' => 50]), 'expected_outcome' => json_encode(['response_time' => '<300ms']), 'actual_outcome' => json_encode(['response_time' => 280]), 'test_passed' => true],
            ['algorithm_id' => 2, 'test_scenario' => 'Multiple responders', 'input_data' => json_encode(['responders' => 10]), 'expected_outcome' => json_encode(['load_balanced' => true]), 'actual_outcome' => json_encode(['load_balanced' => true]), 'test_passed' => true],
            ['algorithm_id' => 3, 'test_scenario' => 'Low supply case', 'input_data' => json_encode(['resources' => 3]), 'expected_outcome' => json_encode(['alert_triggered' => true]), 'actual_outcome' => json_encode(['alert_triggered' => true]), 'test_passed' => true],
            ['algorithm_id' => 4, 'test_scenario' => 'Network delay', 'input_data' => json_encode(['latency' => 500]), 'expected_outcome' => json_encode(['timeout' => false]), 'actual_outcome' => json_encode(['timeout' => false]), 'test_passed' => true],
            ['algorithm_id' => 5, 'test_scenario' => 'AI prediction variance', 'input_data' => json_encode(['forecast_diff' => 10]), 'expected_outcome' => json_encode(['retrain' => false]), 'actual_outcome' => json_encode(['retrain' => false]), 'test_passed' => true],
        ];

        foreach ($data as $item) {
            AllocationTest::create($item);
        }
    }
}
