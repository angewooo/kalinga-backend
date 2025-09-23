<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id('warehouse_id');
            $table->string('name', 100);
            $table->text('location');
            $table->integer('capacity')->nullable();
            $table->foreignId('manager_id')->nullable()->constrained('users');
            $table->timestamp('last_updated')->useCurrent();
        });

        Schema::create('supply_orders', function (Blueprint $table) {
            $table->id('order_id');
            $table->foreignId('hospital_id')->nullable()->constrained('hospitals','hospital_id');
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers','supplier_id');
            $table->foreignId('resource_id')->nullable()->constrained('hospital_resources','resource_id');
            $table->integer('quantity');
            $table->string('status', 20)->default('pending');
            $table->decimal('unit_price', 8, 2)->nullable();
            $table->decimal('total_cost', 10, 2)->nullable();
            $table->string('budget_code', 20)->nullable();
            $table->timestamp('order_date')->useCurrent();
            $table->timestamp('delivery_date')->nullable();
            $table->text('notes')->nullable();
        });

        Schema::create('resource_allocations', function (Blueprint $table) {
            $table->id('allocation_id');
            $table->foreignId('resource_id')->nullable()->constrained('hospital_resources','resource_id');
            $table->foreignId('from_hospital')->nullable()->constrained('hospitals','hospital_id');
            $table->foreignId('to_hospital')->nullable()->constrained('hospitals','hospital_id');
            $table->integer('quantity');
            $table->string('reason', 100)->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('allocated_at')->useCurrent();
        });

        Schema::create('inventory_logs', function (Blueprint $table) {
            $table->id('log_id');
            $table->foreignId('resource_id')->nullable()->constrained('hospital_resources','resource_id');
            $table->foreignId('responder_id')->nullable()->constrained('responders','responder_id');
            $table->string('action_type', 20);
            $table->integer('quantity');
            $table->text('notes')->nullable();
            $table->timestamp('timestamp')->useCurrent();
        });

        Schema::create('transport_routes', function (Blueprint $table) {
            $table->id('transport_id');
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles','vehicle_id');
            $table->foreignId('start_hospital')->nullable()->constrained('hospitals','hospital_id');
            $table->foreignId('end_hospital')->nullable()->constrained('hospitals','hospital_id');
            $table->json('route_details')->nullable();
            $table->integer('estimated_time')->nullable();
            $table->decimal('distance_km', 8, 3)->nullable();
            $table->string('status', 20)->default('planned');
            $table->timestamp('departure_time')->nullable();
            $table->timestamp('arrival_time')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('transport_routes');
        Schema::dropIfExists('inventory_logs');
        Schema::dropIfExists('resource_allocations');
        Schema::dropIfExists('supply_orders');
        Schema::dropIfExists('warehouses');
    }
};
