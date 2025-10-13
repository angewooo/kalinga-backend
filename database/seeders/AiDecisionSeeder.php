<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\AiDecision;

class AiDecisionSeeder extends Seeder
{
    public function run()
    {
        $data = [
            ['model_id' => 1, 'decision_type' => 'Resource Allocation', 'input_data' => json_encode(['hospital_id' => 1, 'demand' => 120]), 'decision_result' => json_encode(['allocated' => 100]), 'confidence_score' => 0.9321, 'human_override' => false],
            ['model_id' => 2, 'decision_type' => 'Forecast Update', 'input_data' => json_encode(['week' => 40]), 'decision_result' => json_encode(['forecast_demand' => 220]), 'confidence_score' => 0.8912, 'human_override' => false],
            ['model_id' => 1, 'decision_type' => 'Critical Supply Alert', 'input_data' => json_encode(['resource' => 'Oxygen']), 'decision_result' => json_encode(['alert_sent' => true]), 'confidence_score' => 0.9578, 'human_override' => true],
            ['model_id' => 3, 'decision_type' => 'Route Optimization', 'input_data' => json_encode(['distance_km' => 8.3]), 'decision_result' => json_encode(['optimal_route' => 'Route A']), 'confidence_score' => 0.8744, 'human_override' => false],
            ['model_id' => 2, 'decision_type' => 'Responder Dispatch', 'input_data' => json_encode(['incident_id' => 5]), 'decision_result' => json_encode(['responder_id' => 3]), 'confidence_score' => 0.9033, 'human_override' => false],
        ];

        foreach ($data as $item) {
            AiDecision::create($item);
        }
    }
}
