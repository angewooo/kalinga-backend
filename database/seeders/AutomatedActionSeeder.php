<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\AutomatedAction;

class AutomatedActionSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            [
                'trigger_condition' => json_encode(['metric' => 'CPU Usage', 'threshold' => '>80']),
                'action_type' => 'Alert',
                'target_table' => 'system_performance',
                'action_parameters' => json_encode(['email' => 'admin@kalinga.com']),
                'is_active' => true,
                'created_by' => 1
            ],
            [
                'trigger_condition' => json_encode(['metric' => 'Memory Usage', 'threshold' => '>85']),
                'action_type' => 'ScaleUp',
                'target_table' => 'servers',
                'action_parameters' => json_encode(['server_count' => 2]),
                'is_active' => true,
                'created_by' => 1
            ],
            [
                'trigger_condition' => json_encode(['metric' => 'Network Latency', 'threshold' => '>100']),
                'action_type' => 'RestartService',
                'target_table' => 'network_services',
                'action_parameters' => json_encode(['service' => 'gateway']),
                'is_active' => true,
                'created_by' => 2
            ],
            [
                'trigger_condition' => json_encode(['metric' => 'Disk Space', 'threshold' => '<15']),
                'action_type' => 'Cleanup',
                'target_table' => 'storage_logs',
                'action_parameters' => json_encode(['days' => 30]),
                'is_active' => false,
                'created_by' => 1
            ],
            [
                'trigger_condition' => json_encode(['metric' => 'API Response Time', 'threshold' => '>500']),
                'action_type' => 'RestartAPI',
                'target_table' => 'api_services',
                'action_parameters' => json_encode(['endpoint' => '/v1/system']),
                'is_active' => true,
                'created_by' => 3
            ],
        ];

        foreach ($data as $item) {
            AutomatedAction::create($item);
        }
    }
}
