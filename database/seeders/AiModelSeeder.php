<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\AiModel;

class AiModelSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            ['model_name' => 'Demand Predictor', 'model_type' => 'forecasting', 'accuracy_score' => 0.9123, 'training_data_size' => 5000, 'last_trained' => now(), 'model_parameters' => json_encode(['layers' => 3, 'optimizer' => 'adam'])],
            ['model_name' => 'Resource Allocator', 'model_type' => 'allocation', 'accuracy_score' => 0.8875, 'training_data_size' => 4200, 'last_trained' => now(), 'model_parameters' => json_encode(['algorithm' => 'greedy'])],
            ['model_name' => 'Incident Analyzer', 'model_type' => 'pattern_analysis', 'accuracy_score' => 0.9011, 'training_data_size' => 3700, 'last_trained' => now(), 'model_parameters' => json_encode(['depth' => 4])],
            ['model_name' => 'Supply Chain Optimizer', 'model_type' => 'optimization', 'accuracy_score' => 0.9256, 'training_data_size' => 6100, 'last_trained' => now(), 'model_parameters' => json_encode(['model' => 'lstm'])],
            ['model_name' => 'Health Risk Predictor', 'model_type' => 'risk_assessment', 'accuracy_score' => 0.8944, 'training_data_size' => 5300, 'last_trained' => now(), 'model_parameters' => json_encode(['method' => 'regression'])],
        ];

        foreach ($data as $item) {
            AiModel::create($item);
        }
    }
}
