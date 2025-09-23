<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        // Hospitals
        Schema::create('hospitals', function (Blueprint $table) {
            $table->id('hospital_id');
            $table->string('name', 100);
            $table->text('address');
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->integer('capacity');
            $table->integer('current_load')->default(0);
            $table->string('contact_number', 20)->nullable();
            $table->string('facility_type', 50)->nullable();
            $table->string('doh_classification', 20)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        // Suppliers
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id('supplier_id');
            $table->string('name', 100);
            $table->json('contact_info')->nullable();
            $table->text('address')->nullable();
            $table->timestamps();
        });

        // Hospital resources
        Schema::create('hospital_resources', function (Blueprint $table) {
            $table->id('resource_id');
            $table->foreignId('hospital_id')->constrained('hospitals','hospital_id')->onDelete('cascade');
            $table->string('resource_type', 50);
            $table->integer('quantity_total');
            $table->integer('quantity_available');
            $table->decimal('total_cost', 10, 2)->nullable();
            $table->decimal('cost_per_unit', 8, 2)->nullable();
            $table->string('unit', 20)->nullable();
            $table->timestamp('last_updated')->useCurrent();
        });

        // Resource batches
        Schema::create('resource_batches', function (Blueprint $table) {
            $table->id('batch_id');
            $table->foreignId('resource_id')->constrained('hospital_resources','resource_id')->onDelete('cascade');
            $table->string('batch_number', 50);
            $table->date('manufacture_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers','supplier_id');
            $table->decimal('cost_per_unit', 8, 2)->nullable();
            $table->integer('quantity_received');
            $table->string('quality_status', 20)->default('approved');
            $table->timestamp('received_at')->useCurrent();
        });

        // Resource thresholds
        Schema::create('resource_thresholds', function (Blueprint $table) {
            $table->id('threshold_id');
            $table->foreignId('resource_id')->constrained('hospital_resources','resource_id')->onDelete('cascade');
            $table->integer('min_level');
            $table->integer('max_level');
            $table->boolean('alert_triggered')->default(false);
            $table->timestamp('updated_at')->useCurrent();
        });
    }

    public function down()
    {
        Schema::dropIfExists('resource_thresholds');
        Schema::dropIfExists('resource_batches');
        Schema::dropIfExists('hospital_resources');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('hospitals');
    }
};
