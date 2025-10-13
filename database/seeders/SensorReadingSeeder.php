<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\SensorReading;

class SensorReadingSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            ['sensor_id' => 1, 'value' => 36.8, 'unit' => '°C', 'timestamp' => now()],
            ['sensor_id' => 2, 'value' => 98.5, 'unit' => '%', 'timestamp' => now()],
            ['sensor_id' => 3, 'value' => 45.2, 'unit' => '%', 'timestamp' => now()],
            ['sensor_id' => 4, 'value' => 1.02, 'unit' => 'atm', 'timestamp' => now()],
            ['sensor_id' => 5, 'value' => 0.00, 'unit' => 'ppm', 'timestamp' => now()],
        ];

        foreach ($data as $item) {
            SensorReading::create($item);
        }
    }
}
