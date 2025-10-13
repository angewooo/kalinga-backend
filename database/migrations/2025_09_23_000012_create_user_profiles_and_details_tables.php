<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('user_profiles', function (Blueprint $table) {
    $table->id('profile_id');
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->string('first_name', 100)->nullable();
    $table->string('last_name', 100)->nullable();
    $table->string('contact_number', 20)->nullable();
    $table->string('gender', 10)->nullable();
    $table->text('address')->nullable();
    $table->text('home_address')->nullable();
    $table->string('emergency_contact_name', 100)->nullable();
    $table->string('emergency_contact_number', 20)->nullable();
    $table->string('blood_type', 5)->nullable();
    $table->text('allergies')->nullable();
    $table->text('medical_conditions')->nullable();
    $table->timestamps();
});


        Schema::create('responder_details', function (Blueprint $table) {
            $table->id('responder_detail_id');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('badge_number', 50)->unique()->nullable();
            $table->string('license_number', 50)->nullable();
            $table->string('availability_status', 20)->default('available');
            $table->time('shift_start')->nullable();
            $table->time('shift_end')->nullable();
            $table->timestamps();
        });

        Schema::create('admin_details', function (Blueprint $table) {
            $table->id('admin_id');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('department', 100)->nullable();
            $table->string('access_level', 50)->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('admin_details');
        Schema::dropIfExists('responder_details');
        Schema::dropIfExists('user_profiles');
    }
};
