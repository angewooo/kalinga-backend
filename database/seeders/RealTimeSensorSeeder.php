<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\RealTimeSensor;

class RealTimeSensorSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            ['hospital_id' => 1, 'sensor_type' => 'Temperature', 'location' => 'ER Ward', 'status' => 'active', 'last_ping' => now()],
            ['hospital_id' => 1, 'sensor_type' => 'Oxygen Level', 'location' => 'ICU', 'status' => 'active', 'last_ping' => now()],
            ['hospital_id' => 2, 'sensor_type' => 'Humidity', 'location' => 'Ward 3A', 'status' => 'inactive', 'last_ping' => now()],
            ['hospital_id' => 2, 'sensor_type' => 'Pressure', 'location' => 'Storage Room', 'status' => 'active', 'last_ping' => now()],
            ['hospital_id' => 3, 'sensor_type' => 'Smoke', 'location' => 'Operating Room', 'status' => 'active', 'last_ping' => now()],
        ];

        foreach ($data as $item) {
            RealTimeSensor::create($item);
        }
    }
}
