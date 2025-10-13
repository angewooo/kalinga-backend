<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PasswordResetTokenSeeder extends Seeder
{
    public function run()
    {
        DB::table('password_reset_tokens')->upsert([
    ['email' => 'admin@kalinga.com', 'token' => Str::random(64), 'created_at' => now()],
    ['email' => 'system@kalinga.com', 'token' => Str::random(64), 'created_at' => now()],
], ['email']);

    }
}
