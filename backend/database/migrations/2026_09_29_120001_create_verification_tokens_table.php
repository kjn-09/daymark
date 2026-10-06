<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("CREATE TYPE verification_token_type AS ENUM ('email_verify', 'mobile_otp', 'password_reset', 'account_unlock')");
        }

        Schema::create('verification_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('token_hash', 255);
            if (DB::connection()->getDriverName() === 'pgsql') {
                $table->string('type');
            } else {
                $table->enum('type', ['email_verify', 'mobile_otp', 'password_reset', 'account_unlock']);
            }
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE verification_tokens ALTER COLUMN type TYPE verification_token_type USING type::verification_token_type');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_tokens');

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP TYPE IF EXISTS verification_token_type');
        }
    }
};
