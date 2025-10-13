<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Hospital;

class HospitalSeeder extends Seeder
{
    public function run(): void
    {
        Hospital::create([
            'name' => 'Rizal Medical Center',
            'address' => 'Pasig City, Metro Manila',
            'latitude' => 14.5612,
            'longitude' => 121.0763,
            'capacity' => 500,
            'current_load' => 420,
            'contact_number' => '0286472314',
            'facility_type' => 'Government',
            'doh_classification' => 'Level 3',
            'status' => 'active',
        ]);

        Hospital::create([
            'name' => 'The Medical City',
            'address' => 'Ortigas Ave, Pasig City',
            'latitude' => 14.5887,
            'longitude' => 121.0816,
            'capacity' => 350,
            'current_load' => 290,
            'contact_number' => '0289891000',
            'facility_type' => 'Private',
            'doh_classification' => 'Level 3',
            'status' => 'active',
        ]);

        Hospital::create([
            'name' => 'Amang Rodriguez Memorial Medical Center',
            'address' => 'Marikina City, Metro Manila',
            'latitude' => 14.6463,
            'longitude' => 121.1008,
            'capacity' => 250,
            'current_load' => 200,
            'contact_number' => '0286822225',
            'facility_type' => 'Government',
            'doh_classification' => 'Level 2',
            'status' => 'active',
        ]);
    }
}
