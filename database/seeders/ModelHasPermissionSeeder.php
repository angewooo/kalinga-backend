<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ModelHasPermissionSeeder extends Seeder
{
    public function run()
    {
        $data = [
            ['permission_id' => 1, 'model_type' => 'App\Models\User', 'model_id' => 1],
            ['permission_id' => 2, 'model_type' => 'App\Models\User', 'model_id' => 1],
            ['permission_id' => 3, 'model_type' => 'App\Models\User', 'model_id' => 2],
            ['permission_id' => 4, 'model_type' => 'App\Models\User', 'model_id' => 2],
            ['permission_id' => 5, 'model_type' => 'App\Models\User', 'model_id' => 3],
        ];

        DB::table('model_has_permissions')->insert($data);
    }
}
