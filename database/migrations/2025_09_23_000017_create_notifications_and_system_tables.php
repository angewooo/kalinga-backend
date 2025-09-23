<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id('notif_id');
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('title', 100);
            $table->text('message');
            $table->string('type', 30);
            $table->string('status', 20)->default('unread');
            $table->integer('priority_level')->default(3);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('notification_channels', function (Blueprint $table) {
            $table->id('channel_id');
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('channel_type', 50);
            $table->string('address', 255);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id('log_id');
            $table->foreignId('notif_id')->constrained('notifications','notif_id');
            $table->foreignId('channel_id')->nullable()->constrained('notification_channels','channel_id');
            $table->string('delivery_status', 20)->nullable();
            $table->timestamp('sent_at')->useCurrent();
        });

        Schema::create('automated_actions', function (Blueprint $table) {
            $table->id('action_id');
            $table->json('trigger_condition')->nullable();
            $table->string('action_type', 50)->nullable();
            $table->string('target_table', 50)->nullable();
            $table->json('action_parameters')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
        });

        Schema::create('system_performance', function (Blueprint $table) {
            $table->id('performance_id');
            $table->string('metric_name', 50)->nullable();
            $table->decimal('metric_value', 10, 4)->nullable();
            $table->decimal('benchmark_value', 10, 4)->nullable();
            $table->foreignId('hospital_id')->nullable()->constrained('hospitals','hospital_id');
            $table->timestamp('measured_at')->useCurrent();
        });

        Schema::create('allocation_algorithms', function (Blueprint $table) {
            $table->id('algorithm_id');
            $table->string('algorithm_name', 50)->nullable();
            $table->text('algorithm_description')->nullable();
            $table->decimal('success_rate', 5, 2)->nullable();
            $table->integer('average_response_time')->nullable();
            $table->boolean('is_default')->default(false);
        });

        Schema::create('allocation_tests', function (Blueprint $table) {
            $table->id('test_id');
            $table->foreignId('algorithm_id')->nullable()->constrained('allocation_algorithms','algorithm_id');
            $table->string('test_scenario', 100)->nullable();
            $table->json('input_data')->nullable();
            $table->json('expected_outcome')->nullable();
            $table->json('actual_outcome')->nullable();
            $table->boolean('test_passed')->nullable();
            $table->timestamp('tested_at')->useCurrent();
        });

        Schema::create('delivery_performance', function (Blueprint $table) {
            $table->id('performance_id');
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers','supplier_id');
            $table->decimal('on_time_percentage', 5, 2)->nullable();
            $table->integer('quality_rating')->nullable();
            $table->integer('total_deliveries')->default(0);
            $table->date('evaluation_month');
            $table->timestamps();
        });

        Schema::create('system_health', function (Blueprint $table) {
            $table->id('health_id');
            $table->string('component_name', 50);
            $table->string('status', 20);
            $table->integer('response_time_ms')->nullable();
            $table->decimal('error_rate', 5, 2)->nullable();
            $table->timestamp('last_checked')->useCurrent();
        });
    }

    public function down()
    {
        Schema::dropIfExists('system_health');
        Schema::dropIfExists('delivery_performance');
        Schema::dropIfExists('allocation_tests');
        Schema::dropIfExists('allocation_algorithms');
        Schema::dropIfExists('system_performance');
        Schema::dropIfExists('automated_actions');
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('notification_channels');
        Schema::dropIfExists('notifications');
    }
};
