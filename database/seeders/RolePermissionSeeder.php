<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $roles = ['Admin', 'Dispatcher', 'Responder'];
        foreach ($roles as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'sanctum']);
        }

        $permissions = [
            'manage users',
            'create users',
            'edit users',
            'delete users',
            'update-users',
            'view users',
            'view-users',
            'manage hospitals',
            'view requests',
            'assign responders',
            'monitor system',
        ];
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'sanctum']);
        }

        Role::where('name', 'Admin')->first()->givePermissionTo(Permission::all());
    }
}
