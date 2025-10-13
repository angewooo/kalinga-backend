<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Responder;

class ResponderSeeder extends Seeder
{
    public function run()
    {
        $responders = [
            [
                'user_id' => 2,
                'hospital_id' => 1,
                'specialization' => 'Paramedic',
                'status' => 'available',
                'shift_start' => '08:00:00',
                'shift_end' => '16:00:00',
            ],
            [
                'user_id' => 3,
                'hospital_id' => 1,
                'specialization' => 'Nurse',
                'status' => 'on-duty',
                'shift_start' => '07:00:00',
                'shift_end' => '15:00:00',
            ],
            [
                'user_id' => 1,
                'hospital_id' => 2,
                'specialization' => 'Doctor',
                'status' => 'available',
                'shift_start' => '09:00:00',
                'shift_end' => '17:00:00',
            ],
        ];

        foreach ($responders as $responder) {
            Responder::create($responder);
        }
    }
}
