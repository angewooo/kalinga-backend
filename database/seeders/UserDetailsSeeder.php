<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\AdminDetail;
use App\Models\UserProfile;
use App\Models\ResponderDetail;

class UserDetailsSeeder extends Seeder
{
    public function run(): void
    {
        // 🧱 ADMIN DETAILS + PROFILE
        AdminDetail::create([
            'user_id' => 1,
            'department' => 'Operations Center',
            'access_level' => 'Full',
        ]);

        UserProfile::create([
            'user_id' => 1,
            'first_name' => 'Admin',
            'last_name' => 'User',
            'contact_number' => '09171234567',
            'gender' => 'male',
            'address' => 'Cainta, Rizal',
            'home_address' => 'Cainta, Rizal',
            'emergency_contact_name' => 'Maria Dela Cruz',
            'emergency_contact_number' => '09171234567',
            'blood_type' => 'O+',
            'allergies' => null,
            'medical_conditions' => null,
        ]);

        // 🚑 RESPONDER DETAILS + PROFILE
        ResponderDetail::create([
            'user_id' => 3,
            'badge_number' => 'RSP-1001',
            'license_number' => 'LIC-99999',
            'availability_status' => 'available',
            'shift_start' => '08:00:00',
            'shift_end' => '17:00:00',
        ]);

        UserProfile::create([
            'user_id' => 3,
            'first_name' => 'Responder',
            'last_name' => 'User',
            'contact_number' => '09998887777',
            'gender' => 'male',
            'address' => 'Mandaluyong City',
            'home_address' => 'Mandaluyong City',
            'emergency_contact_name' => 'Juan Responder',
            'emergency_contact_number' => '09998887777',
            'blood_type' => 'A+',
            'allergies' => null,
            'medical_conditions' => null,
        ]);
    }
}
