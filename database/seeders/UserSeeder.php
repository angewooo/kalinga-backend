<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\AdminDetail;
use App\Models\ResponderDetail;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // 🧱 ADMIN USER
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@kalinga.com',
            'password' => Hash::make('password'),
            'account_status' => 'active',
        ]);
        $admin->assignRole('Admin');

        UserProfile::create([
            'user_id' => $admin->id,
            'first_name' => 'Admin',
            'last_name' => 'User',
            'contact_number' => '09171234567',
            'gender' => 'male',
            'address' => 'Cainta, Rizal',
            'home_address' => 'Cainta, Rizal',
            'emergency_contact_name' => 'Maria Dela Cruz',
            'emergency_contact_number' => '09171234567',
            'blood_type' => 'O+',
        ]);

        AdminDetail::create([
            'user_id' => $admin->id,
            'department' => 'Operations Center',
            'access_level' => 'Full',
        ]);

        // 🧭 DISPATCHER USER
        $dispatcher = User::create([
            'name' => 'Dispatcher User',
            'email' => 'dispatcher@kalinga.com',
            'password' => Hash::make('password'),
            'account_status' => 'active',
        ]);
        $dispatcher->assignRole('Dispatcher');

        UserProfile::create([
            'user_id' => $dispatcher->id,
            'first_name' => 'Dispatcher',
            'last_name' => 'User',
            'contact_number' => '09181234567',
            'gender' => 'female',
            'address' => 'Marikina City',
            'home_address' => 'Marikina City',
            'emergency_contact_name' => 'Juan Dela Cruz',
            'emergency_contact_number' => '09181234568',
            'blood_type' => 'A+',
        ]);

        // 🚑 RESPONDER USER
        $responder = User::create([
            'name' => 'Responder User',
            'email' => 'responder@kalinga.com',
            'password' => Hash::make('password'),
            'account_status' => 'active',
        ]);
        $responder->assignRole('Responder');

        UserProfile::create([
            'user_id' => $responder->id,
            'first_name' => 'Responder',
            'last_name' => 'User',
            'contact_number' => '09191234567',
            'gender' => 'male',
            'address' => 'Antipolo City',
            'home_address' => 'Antipolo City',
            'emergency_contact_name' => 'Carla Reyes',
            'emergency_contact_number' => '09191234568',
            'blood_type' => 'B+',
        ]);

        ResponderDetail::create([
            'user_id' => $responder->id,
            'badge_number' => 'RSP-' . strtoupper(uniqid()),
            'license_number' => 'LIC-' . rand(10000, 99999),
            'availability_status' => 'available',
            'shift_start' => '08:00:00',
            'shift_end' => '17:00:00',
        ]);
    }
}
