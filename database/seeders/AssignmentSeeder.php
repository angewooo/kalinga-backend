<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Assignment;

class AssignmentSeeder extends Seeder
{
    public function run()
    {
        $assignments = [
            [
                'request_id' => 1,
                'responder_id' => 1,
                'hospital_id' => 1,
                'vehicle_id' => 1,
                'status' => 'assigned',
                'notes' => 'Responder dispatched from Rizal Medical Center.',
            ],
            [
                'request_id' => 2,
                'responder_id' => 2,
                'hospital_id' => 2,
                'vehicle_id' => 2,
                'status' => 'en-route',
                'notes' => 'Rescue team is on their way to the site.',
            ],
            [
                'request_id' => 3,
                'responder_id' => 3,
                'hospital_id' => 1,
                'vehicle_id' => 3,
                'status' => 'completed',
                'notes' => 'Incident resolved and reported.',
            ],
        ];

        

        foreach ($assignments as $assignment) {
            Assignment::create($assignment);
        }
    }
}
