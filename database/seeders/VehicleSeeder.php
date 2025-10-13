<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Vehicle;

class VehicleSeeder extends Seeder
{
    public function run()
    {
        $vehicles = [
            [
                'hospital_id' => 1,
                'vehicle_type' => 'Ambulance',
                'plate_number' => 'NCA-1010',
                'status' => 'available',
                'capacity' => 4,
            ],
            [
                'hospital_id' => 2,
                'vehicle_type' => 'Rescue Truck',
                'plate_number' => 'RSC-2020',
                'status' => 'maintenance',
                'capacity' => 6,
            ],
            [
                'hospital_id' => 1,
                'vehicle_type' => 'Ambulance',
                'plate_number' => 'NCA-1020',
                'status' => 'on-duty',
                'capacity' => 4,
            ],
        ];

        foreach ($vehicles as $vehicle) {
            Vehicle::create($vehicle);
        }
    }
}
