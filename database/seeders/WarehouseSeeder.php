<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Warehouse;

class WarehouseSeeder extends Seeder
{
    public function run()
    {
        $warehouses = [
            [
                'name' => 'Central Medical Warehouse',
                'location' => 'Pasig City, Metro Manila',
                'capacity' => 10000,
                'manager_id' => 1,
            ],
            [
                'name' => 'North Supply Depot',
                'location' => 'Quezon City, Metro Manila',
                'capacity' => 8000,
                'manager_id' => 2,
            ],
            [
                'name' => 'South Relief Center',
                'location' => 'Muntinlupa City, Metro Manila',
                'capacity' => 6000,
                'manager_id' => 3,
            ],
        ];

        foreach ($warehouses as $data) {
            Warehouse::create($data);
        }
    }
}
