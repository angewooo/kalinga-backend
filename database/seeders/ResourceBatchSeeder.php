<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ResourceBatch;

class ResourceBatchSeeder extends Seeder
{
    public function run()
    {
        $data = [
            ['resource_id' => 1, 'batch_number' => 'BATCH-2025-001', 'manufacture_date' => '2025-06-01', 'expiry_date' => '2026-06-01', 'supplier_id' => 1, 'cost_per_unit' => 120.50, 'quantity_received' => 100, 'quality_status' => 'approved'],
            ['resource_id' => 2, 'batch_number' => 'BATCH-2025-002', 'manufacture_date' => '2025-05-15', 'expiry_date' => '2026-05-15', 'supplier_id' => 2, 'cost_per_unit' => 80.75, 'quantity_received' => 150, 'quality_status' => 'approved'],
            ['resource_id' => 3, 'batch_number' => 'BATCH-2025-003', 'manufacture_date' => '2025-04-20', 'expiry_date' => '2026-04-20', 'supplier_id' => 1, 'cost_per_unit' => 95.00, 'quantity_received' => 200, 'quality_status' => 'pending'],
            ['resource_id' => 1, 'batch_number' => 'BATCH-2025-004', 'manufacture_date' => '2025-07-10', 'expiry_date' => '2026-07-10', 'supplier_id' => 3, 'cost_per_unit' => 110.25, 'quantity_received' => 180, 'quality_status' => 'approved'],
            ['resource_id' => 2, 'batch_number' => 'BATCH-2025-005', 'manufacture_date' => '2025-08-05', 'expiry_date' => '2026-08-05', 'supplier_id' => 2, 'cost_per_unit' => 70.00, 'quantity_received' => 120, 'quality_status' => 'approved'],
        ];

        foreach ($data as $item) {
            ResourceBatch::create($item);
        }
    }
}
