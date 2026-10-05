<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name', 50)->after('name');
            $table->string('last_name', 50)->after('first_name');
            $table->string('middle_initial', 2)->nullable()->after('last_name');
            $table->date('birthday')->after('middle_initial');
            $table->string('mobile_number', 20)->unique()->after('password');
            $table->boolean('mobile_verified')->default(false)->after('mobile_number');
            $table->unsignedTinyInteger('failed_login_attempts')->default(0);
            $table->boolean('is_locked')->default(false);
            $table->timestamp('locked_until')->nullable();
        });

        Schema::create('addresses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('house_street');
            $table->string('country', 100);
            $table->string('city', 100);
            $table->string('state', 100);
            $table->string('zip_code', 20);
            $table->timestamps();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['mobile_number']);
            $table->dropColumn([
                'first_name', 'last_name', 'middle_initial', 'birthday', 'mobile_number',
                'mobile_verified', 'failed_login_attempts', 'is_locked', 'locked_until',
            ]);
        });
    }
};
