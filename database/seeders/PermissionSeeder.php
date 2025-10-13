<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PermissionSeeder extends Seeder
{
    public function run()
    {
        $permissions = [
            'view users', 'edit users', 'delete users',
            'view hospitals', 'edit hospitals',
            'manage resources', 'allocate resources',
            'view reports', 'generate forecasts', 'manage notifications'
        ];

        foreach ($permissions as $perm) {
            Permission::create(['name' => $perm]);
        }
    }
}
