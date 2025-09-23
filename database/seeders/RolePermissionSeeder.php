<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolePermissionSeeder extends Seeder
{
    public function run()
    {
        $permissions = [
            'view-dashboard',
            'manage-hospitals',
            'handle-requests',
            'manage-resources',
            'view-reports',
            'manage-users',
            'manage-suppliers',
            'view-analytics',
            'handle-assignments',
            'manage-vehicles',
            'view-notifications',
            'manage-system'
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $admin = Role::firstOrCreate(['name' => 'admin']);
        $responder = Role::firstOrCreate(['name' => 'responder']);
        $hospital_admin = Role::firstOrCreate(['name' => 'hospital_admin']);
        $citizen = Role::firstOrCreate(['name' => 'citizen']);
        $superadmin = Role::firstOrCreate(['name' => 'superadmin']);

        // assign permissions
        $admin->givePermissionTo(Permission::all());
        $responder->givePermissionTo(['view-dashboard','handle-requests','handle-assignments','view-notifications']);
        $hospital_admin->givePermissionTo(['view-dashboard','manage-resources','view-reports','manage-suppliers','view-analytics','view-notifications']);
        $citizen->givePermissionTo(['view-dashboard','view-notifications']);
        $superadmin->givePermissionTo(Permission::all());
    }
}
