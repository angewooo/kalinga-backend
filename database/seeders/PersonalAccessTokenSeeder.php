<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PersonalAccessTokenSeeder extends Seeder
{
    public function run()
    {
        DB::table('personal_access_tokens')->insert([
            [
                'tokenable_type' => 'App\\Models\\User',
                'tokenable_id' => 1,
                'name' => 'Admin Token',
                'token' => hash('sha256', Str::random(40)),
                'abilities' => '["*"]',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tokenable_type' => 'App\\Models\\User',
                'tokenable_id' => 2,
                'name' => 'System Token',
                'token' => hash('sha256', Str::random(40)),
                'abilities' => '["read"]',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
