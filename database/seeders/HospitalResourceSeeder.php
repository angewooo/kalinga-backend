<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\HospitalResource;

class HospitalResourceSeeder extends Seeder
{
    public function run(): void
    {
        HospitalResource::create([
            'hospital_id' => 1,
            'resource_type' => 'Oxygen Tanks',
            'quantity_total' => 100,
            'quantity_available' => 80,
            'total_cost' => 50000,
            'cost_per_unit' => 500,
            'unit' => 'pcs',
        ]);

        HospitalResource::create([
            'hospital_id' => 2,
            'resource_type' => 'IV Fluids',
            'quantity_total' => 200,
            'quantity_available' => 180,
            'total_cost' => 40000,
            'cost_per_unit' => 200,
            'unit' => 'bottles',
        ]);

        HospitalResource::create([
            'hospital_id' => 3,
            'resource_type' => 'Ventilators',
            'quantity_total' => 20,
            'quantity_available' => 18,
            'total_cost' => 1000000,
            'cost_per_unit' => 50000,
            'unit' => 'units',
        ]);
    }
}
