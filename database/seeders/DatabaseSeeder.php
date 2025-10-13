<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
    RolePermissionSeeder::class,
    UserSeeder::class,
    UserDetailsSeeder::class,
    HospitalSeeder::class,
    HospitalResourceSeeder::class,
    SupplierSeeder::class,
    ResponderSeeder::class,
    RequestSeeder::class,
    VehicleSeeder::class,
    AssignmentSeeder::class,
    WarehouseSeeder::class,
    SupplyOrderSeeder::class,
    ResourceAllocationSeeder::class,
    InventoryLogSeeder::class,
    TransportRouteSeeder::class,
    AiModelSeeder::class,
    ForecastResultSeeder::class,
    ModelRetrainingSeeder::class,
    PredictionAccuracySeeder::class,
    RealTimeSensorSeeder::class,
    SensorReadingSeeder::class,
    SystemHealthSeeder::class,
    SystemPerformanceSeeder::class,
    AiDecisionSeeder::class,
    NotificationChannelSeeder::class,
    NotificationSeeder::class,
    NotificationLogSeeder::class,
    AutomatedActionSeeder::class,
    HistoricalDemandSeeder::class,
    AllocationAlgorithmSeeder::class,
    ResourceBatchSeeder::class,
    ResourceThresholdSeeder::class,
    ModelHasPermissionSeeder::class,
    PersonalAccessTokenSeeder::class,
    PasswordResetTokenSeeder::class,
    SessionSeeder::class
]);

    }
}
