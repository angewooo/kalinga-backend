<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ForecastResult;

class ForecastResultSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            ['hospital_id' => 1, 'resource_type' => 'Oxygen Tanks', 'predicted_demand' => 230, 'confidence_level' => 0.92, 'model_used' => 'Demand Predictor', 'forecast_date' => now()],
            ['hospital_id' => 2, 'resource_type' => 'IV Fluids', 'predicted_demand' => 410, 'confidence_level' => 0.89, 'model_used' => 'Resource Allocator', 'forecast_date' => now()],
            ['hospital_id' => 3, 'resource_type' => 'Syringes', 'predicted_demand' => 180, 'confidence_level' => 0.95, 'model_used' => 'Incident Analyzer', 'forecast_date' => now()],
            ['hospital_id' => 1, 'resource_type' => 'Face Masks', 'predicted_demand' => 340, 'confidence_level' => 0.87, 'model_used' => 'Supply Chain Optimizer', 'forecast_date' => now()],
            ['hospital_id' => 2, 'resource_type' => 'Gloves', 'predicted_demand' => 295, 'confidence_level' => 0.91, 'model_used' => 'Health Risk Predictor', 'forecast_date' => now()],
        ];

        foreach ($data as $item) {
            ForecastResult::create($item);
        }
    }
}
