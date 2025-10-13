<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\DeliveryPerformance;

class DeliveryPerformanceSeeder extends Seeder
{
    public function run()
    {
        $data = [
            ['supplier_id' => 1, 'on_time_percentage' => 98.5, 'quality_rating' => 5, 'total_deliveries' => 40, 'evaluation_month' => '2025-09-01'],
            ['supplier_id' => 2, 'on_time_percentage' => 92.3, 'quality_rating' => 4, 'total_deliveries' => 35, 'evaluation_month' => '2025-09-01'],
            ['supplier_id' => 3, 'on_time_percentage' => 95.8, 'quality_rating' => 5, 'total_deliveries' => 50, 'evaluation_month' => '2025-09-01'],
            ['supplier_id' => 1, 'on_time_percentage' => 90.1, 'quality_rating' => 3, 'total_deliveries' => 25, 'evaluation_month' => '2025-08-01'],
            ['supplier_id' => 2, 'on_time_percentage' => 97.2, 'quality_rating' => 5, 'total_deliveries' => 60, 'evaluation_month' => '2025-08-01'],
        ];

        foreach ($data as $item) {
            DeliveryPerformance::create($item);
        }
    }
}
