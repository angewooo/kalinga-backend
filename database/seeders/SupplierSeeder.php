<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Supplier;

class SupplierSeeder extends Seeder
{
    public function run()
    {
        $suppliers = [
            [
                'name' => 'MedSupply PH',
                'contact_info' => json_encode([
                    'person' => 'Carlos Santos',
                    'number' => '09178889999',
                    'email' => 'carlos@medsupplyph.com'
                ]),
                'address' => 'Cainta, Rizal',
            ],
            [
                'name' => 'HealthPro Logistics',
                'contact_info' => json_encode([
                    'person' => 'Ana Reyes',
                    'number' => '09171234567',
                    'email' => 'ana@healthprologistics.com'
                ]),
                'address' => 'Pasig City, Metro Manila',
            ],
            [
                'name' => 'BioLine Medical Supplies',
                'contact_info' => json_encode([
                    'person' => 'Mark Dela Cruz',
                    'number' => '09189998888',
                    'email' => 'mark@biolineph.com'
                ]),
                'address' => 'Quezon City, Metro Manila',
            ],
        ];

        foreach ($suppliers as $data) {
            Supplier::create($data);
        }
    }
}
