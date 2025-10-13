<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ModelRetraining;

class ModelRetrainingSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            ['model_id' => 1, 'reason' => 'Performance drift', 'old_accuracy' => 0.88, 'new_accuracy' => 0.93, 'training_duration_minutes' => 45],
            ['model_id' => 2, 'reason' => 'New data added', 'old_accuracy' => 0.84, 'new_accuracy' => 0.9, 'training_duration_minutes' => 40],
            ['model_id' => 3, 'reason' => 'Algorithm update', 'old_accuracy' => 0.79, 'new_accuracy' => 0.85, 'training_duration_minutes' => 38],
            ['model_id' => 4, 'reason' => 'Improved preprocessing', 'old_accuracy' => 0.82, 'new_accuracy' => 0.89, 'training_duration_minutes' => 52],
            ['model_id' => 5, 'reason' => 'Seasonal adjustment', 'old_accuracy' => 0.86, 'new_accuracy' => 0.9, 'training_duration_minutes' => 44],
        ];

        foreach ($data as $item) {
            ModelRetraining::create($item);
        }
    }
}
