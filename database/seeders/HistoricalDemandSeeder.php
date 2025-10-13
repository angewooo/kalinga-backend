<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\HistoricalDemand;

class HistoricalDemandSeeder extends Seeder
{
    public function run()
    {
        $data = [
            ['hospital_id' => 1, 'resource_id' => 1, 'demand_quantity' => 120, 'hour_of_day' => 8, 'day_of_week' => 1],
            ['hospital_id' => 2, 'resource_id' => 3, 'demand_quantity' => 200, 'hour_of_day' => 14, 'day_of_week' => 3],
            ['hospital_id' => 1, 'resource_id' => 2, 'demand_quantity' => 150, 'hour_of_day' => 10, 'day_of_week' => 2],
            ['hospital_id' => 3, 'resource_id' => 3, 'demand_quantity' => 90, 'hour_of_day' => 9, 'day_of_week' => 4],
            ['hospital_id' => 2, 'resource_id' => 1, 'demand_quantity' => 130, 'hour_of_day' => 16, 'day_of_week' => 5],
        ];

        foreach ($data as $item) {
            HistoricalDemand::create($item);
        }
    }
}
