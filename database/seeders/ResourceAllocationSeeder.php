<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ResourceAllocation;

class ResourceAllocationSeeder extends Seeder
{
    public function run()
    {
        $allocations = [
            [
                'resource_id' => 1,
                'from_hospital' => 1,
                'to_hospital' => 2,
                'quantity' => 50,
                'reason' => 'Emergency transfer due to shortage',
                'status' => 'approved',
            ],
            [
                'resource_id' => 2,
                'from_hospital' => 2,
                'to_hospital' => 3,
                'quantity' => 30,
                'reason' => 'Redistribution for balance',
                'status' => 'completed',
            ],
        ];

        foreach ($allocations as $allocation) {
            ResourceAllocation::create($allocation);
        }
    }
}
