<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ResourceThreshold;

class ResourceThresholdSeeder extends Seeder
{
    public function run()
    {
        $data = [
            ['resource_id' => 1, 'min_level' => 20, 'max_level' => 200, 'alert_triggered' => false],
            ['resource_id' => 2, 'min_level' => 15, 'max_level' => 150, 'alert_triggered' => true],
            ['resource_id' => 3, 'min_level' => 10, 'max_level' => 100, 'alert_triggered' => false],
            ['resource_id' => 1, 'min_level' => 25, 'max_level' => 250, 'alert_triggered' => false],
            ['resource_id' => 2, 'min_level' => 30, 'max_level' => 300, 'alert_triggered' => false],
        ];

        foreach ($data as $item) {
            ResourceThreshold::create($item);
        }
    }
}
