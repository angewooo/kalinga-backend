<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('responders', function (Blueprint $table) {
            $table->id('responder_id');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('hospital_id')->nullable()->constrained('hospitals','hospital_id');
            $table->string('specialization', 100)->nullable();
            $table->string('status', 20)->default('available');
            $table->time('shift_start')->nullable();
            $table->time('shift_end')->nullable();
            $table->timestamp('last_updated')->useCurrent();
        });

        Schema::create('requests', function (Blueprint $table) {
            $table->id('request_id');
            $table->string('citizen_name', 100)->nullable();
            $table->string('citizen_contact', 20)->nullable();
            $table->string('incident_type', 50);
            $table->string('severity_level', 20)->default('medium');
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->text('address')->nullable();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('vehicles', function (Blueprint $table) {
            $table->id('vehicle_id');
            $table->foreignId('hospital_id')->nullable()->constrained('hospitals','hospital_id');
            $table->string('vehicle_type', 30);
            $table->string('plate_number', 20)->unique();
            $table->string('status', 20)->default('available');
            $table->integer('capacity')->nullable();
            $table->timestamp('last_updated')->useCurrent();
        });

        Schema::create('assignments', function (Blueprint $table) {
            $table->id('assignment_id');
            $table->foreignId('request_id')->constrained('requests','request_id')->onDelete('cascade');
            $table->foreignId('responder_id')->nullable()->constrained('responders','responder_id');
            $table->foreignId('hospital_id')->nullable()->constrained('hospitals','hospital_id');
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles','vehicle_id');
            $table->string('status', 20)->default('assigned');
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
        });
    }

    public function down()
    {
        Schema::dropIfExists('assignments');
        Schema::dropIfExists('vehicles');
        Schema::dropIfExists('requests');
        Schema::dropIfExists('responders');
    }
};
