<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\AdminDetail;

class AdminDetailSeeder extends Seeder
{
    public function run()
    {
        $data = [
            ['user_id' => 1, 'department' => 'Emergency Operations', 'access_level' => 'Full'],
            ['user_id' => 2, 'department' => 'Logistics', 'access_level' => 'Partial'],
            ['user_id' => 3, 'department' => 'Supply Chain', 'access_level' => 'Read'],
            ['user_id' => 1, 'department' => 'System Administration', 'access_level' => 'Full'],
            ['user_id' => 2, 'department' => 'AI Forecasting', 'access_level' => 'Full'],
        ];

        foreach ($data as $item) {
            AdminDetail::create($item);
        }
    }
}
