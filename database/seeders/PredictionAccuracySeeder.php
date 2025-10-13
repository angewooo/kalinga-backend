<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PredictionAccuracy;

class PredictionAccuracySeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            ['forecast_id' => 1, 'actual_demand' => 240, 'prediction_error' => 10, 'error_percentage' => 4.1],
            ['forecast_id' => 2, 'actual_demand' => 400, 'prediction_error' => 10, 'error_percentage' => 2.4],
            ['forecast_id' => 3, 'actual_demand' => 175, 'prediction_error' => 5, 'error_percentage' => 2.8],
            ['forecast_id' => 4, 'actual_demand' => 350, 'prediction_error' => 10, 'error_percentage' => 2.9],
            ['forecast_id' => 5, 'actual_demand' => 310, 'prediction_error' => 15, 'error_percentage' => 4.8],
        ];

        foreach ($data as $item) {
            PredictionAccuracy::create($item);
        }
    }
}
