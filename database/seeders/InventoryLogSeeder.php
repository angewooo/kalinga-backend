<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\InventoryLog;

class InventoryLogSeeder extends Seeder
{
    public function run()
    {
        $logs = [
            [
                'resource_id' => 1,
                'responder_id' => 1,
                'action_type' => 'dispatch',
                'quantity' => 10,
                'notes' => 'Used during emergency response operation.',
            ],
            [
                'resource_id' => 2,
                'responder_id' => 2,
                'action_type' => 'restock',
                'quantity' => 20,
                'notes' => 'Replenished from supplier order.',
            ],
        ];

        foreach ($logs as $log) {
            InventoryLog::create($log);
        }
    }
}
