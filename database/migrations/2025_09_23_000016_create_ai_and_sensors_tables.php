<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('real_time_sensors', function (Blueprint $table) {
            $table->id('sensor_id');
            $table->foreignId('hospital_id')->nullable()->constrained('hospitals','hospital_id');
            $table->string('sensor_type', 50);
            $table->string('location', 100)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('last_ping')->useCurrent();
        });

        Schema::create('sensor_readings', function (Blueprint $table) {
            $table->id('reading_id');
            $table->foreignId('sensor_id')->constrained('real_time_sensors','sensor_id');
            $table->decimal('value', 10, 2);
            $table->string('unit', 20)->nullable();
            $table->timestamp('timestamp')->useCurrent();
        });

        Schema::create('ai_models', function (Blueprint $table) {
            $table->id('model_id');
            $table->string('model_name', 100);
            $table->string('model_type', 50)->nullable();
            $table->decimal('accuracy_score', 5, 4)->nullable();
            $table->integer('training_data_size')->nullable();
            $table->timestamp('last_trained')->nullable();
            $table->json('model_parameters')->nullable();
            
        });

        Schema::create('ai_decisions', function (Blueprint $table) {
            $table->id('decision_id');
            $table->foreignId('model_id')->nullable()->constrained('ai_models','model_id');
            $table->string('decision_type', 50)->nullable();
            $table->json('input_data')->nullable();
            $table->json('decision_result')->nullable();
            $table->decimal('confidence_score', 5, 4)->nullable();
            $table->boolean('human_override')->default(false);
            $table->timestamps();
        });

        Schema::create('historical_demand', function (Blueprint $table) {
            $table->id('demand_id');
            $table->foreignId('hospital_id')->nullable()->constrained('hospitals','hospital_id');
            $table->foreignId('resource_id')->nullable()->constrained('hospital_resources','resource_id');
            $table->integer('demand_quantity');
            $table->integer('hour_of_day')->nullable();
            $table->integer('day_of_week')->nullable();
            $table->timestamp('recorded_at')->useCurrent();
        });

        Schema::create('forecast_results', function (Blueprint $table) {
            $table->id('forecast_id');
            $table->foreignId('hospital_id')->nullable()->constrained('hospitals','hospital_id');
            $table->string('resource_type', 50);
            $table->integer('predicted_demand');
            $table->decimal('confidence_level', 5, 2)->nullable();
            $table->string('model_used', 30)->default('linear_regression');
            $table->timestamp('forecast_date');
            $table->timestamps();
        });

        Schema::create('prediction_accuracy', function (Blueprint $table) {
            $table->id('accuracy_id');
            $table->foreignId('forecast_id')->constrained('forecast_results','forecast_id');
            $table->integer('actual_demand');
            $table->integer('prediction_error')->nullable();
            $table->decimal('error_percentage', 5, 2)->nullable();
            $table->timestamp('recorded_at')->useCurrent();
        });

        Schema::create('model_retraining', function (Blueprint $table) {
            $table->id('retrain_id');
            $table->foreignId('model_id')->nullable()->constrained('ai_models','model_id');
            $table->string('reason', 100)->nullable();
            $table->decimal('old_accuracy', 5, 4)->nullable();
            $table->decimal('new_accuracy', 5, 4)->nullable();
            $table->integer('training_duration_minutes')->nullable();
            $table->timestamp('retrained_at')->useCurrent();
        });
    }

    public function down()
    {
        Schema::dropIfExists('model_retraining');
        Schema::dropIfExists('prediction_accuracy');
        Schema::dropIfExists('forecast_results');
        Schema::dropIfExists('historical_demand');
        Schema::dropIfExists('ai_decisions');
        Schema::dropIfExists('ai_models');
        Schema::dropIfExists('sensor_readings');
        Schema::dropIfExists('real_time_sensors');
    }
};
