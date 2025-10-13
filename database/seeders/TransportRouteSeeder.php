<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\TransportRoute;

class TransportRouteSeeder extends Seeder
{
    public function run()
    {
        $routes = [
            [
                'vehicle_id' => 1,
                'start_hospital' => 1,
                'end_hospital' => 2,
                'route_details' => json_encode(['route' => 'Pasig → Cainta', 'via' => 'Ortigas Ext.']),
                'estimated_time' => 25,
                'distance_km' => 8.4,
                'status' => 'completed',
                'departure_time' => '2025-10-09 08:00:00',
                'arrival_time' => '2025-10-09 08:25:00',
            ],
            [
                'vehicle_id' => 2,
                'start_hospital' => 2,
                'end_hospital' => 3,
                'route_details' => json_encode(['route' => 'Cainta → Antipolo', 'via' => 'Marcos Hwy']),
                'estimated_time' => 30,
                'distance_km' => 10.1,
                'status' => 'planned',
                'departure_time' => null,
                'arrival_time' => null,
            ],
        ];

        foreach ($routes as $route) {
            TransportRoute::create($route);
        }
    }
}
