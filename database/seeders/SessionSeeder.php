<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SessionSeeder extends Seeder
{
    public function run()
    {
        DB::table('sessions')->insert([
            [
                'id' => Str::random(40),
                'user_id' => 1,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PostmanRuntime/7.35.0',
                'payload' => base64_encode('session_data_1'),
                'last_activity' => time(),
            ],
            [
                'id' => Str::random(40),
                'user_id' => 2,
                'ip_address' => '192.168.0.12',
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0)',
                'payload' => base64_encode('session_data_2'),
                'last_activity' => time(),
            ],
        ]);
    }
}
