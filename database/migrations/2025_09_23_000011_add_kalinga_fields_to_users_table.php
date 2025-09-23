<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('qr_code', 255)->unique()->nullable();
            $table->string('account_status', 20)->default('active');
            $table->timestamp('last_active')->nullable();
            $table->integer('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'qr_code',
                'account_status',
                'last_active',
                'failed_login_attempts',
                'locked_until'
            ]);
        });
    }
};
