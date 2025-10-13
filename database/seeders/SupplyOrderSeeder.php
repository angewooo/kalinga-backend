<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\SupplyOrder;

class SupplyOrderSeeder extends Seeder
{
    public function run()
    {
        $orders = [
            [
                'hospital_id' => 1,
                'supplier_id' => 1,
                'resource_id' => 1,
                'quantity' => 100,
                'status' => 'pending',
                'unit_price' => 250.00,
                'total_cost' => 25000.00,
                'budget_code' => 'MED-001',
                'notes' => 'Oxygen tank replenishment',
            ],
            [
                'hospital_id' => 2,
                'supplier_id' => 2,
                'resource_id' => 2,
                'quantity' => 200,
                'status' => 'delivered',
                'unit_price' => 50.00,
                'total_cost' => 10000.00,
                'budget_code' => 'SUP-002',
                'notes' => 'PPE kits delivery',
            ],
        ];

        foreach ($orders as $order) {
            SupplyOrder::create($order);
        }
    }
}
