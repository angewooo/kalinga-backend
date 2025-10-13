<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\RequestEntry;

class RequestSeeder extends Seeder
{
    public function run()
    {
        $requests = [
            [
                'citizen_name' => 'Juan Dela Cruz',
                'citizen_contact' => '09170000001',
                'incident_type' => 'Medical Emergency',
                'severity_level' => 'high',
                'latitude' => 14.5800,
                'longitude' => 121.0600,
                'address' => 'Pasig City',
                'description' => 'Heart attack reported at Pasig Rotonda.',
                'status' => 'pending',
            ],
            [
                'citizen_name' => 'Maria Santos',
                'citizen_contact' => '09171234567',
                'incident_type' => 'Vehicular Accident',
                'severity_level' => 'critical',
                'latitude' => 14.5895,
                'longitude' => 121.0822,
                'address' => 'Cainta Junction',
                'description' => 'Multi-car collision with multiple injuries.',
                'status' => 'in-progress',
            ],
            [
                'citizen_name' => 'Rico Manalo',
                'citizen_contact' => '09182345678',
                'incident_type' => 'Fire Incident',
                'severity_level' => 'medium',
                'latitude' => 14.5500,
                'longitude' => 121.0300,
                'address' => 'Mandaluyong City',
                'description' => 'Fire reported in residential area.',
                'status' => 'resolved',
            ],
        ];

        foreach ($requests as $request) {
            RequestEntry::create($request);
        }
    }
}
