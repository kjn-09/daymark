<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->integer('failed_login_attempts')->default(0)->change();
        });

        Schema::table('verification_tokens', function (Blueprint $table): void {
            $table->string('token_hash', 255)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('verification_tokens', function (Blueprint $table): void {
            $table->char('token_hash', 64)->nullable(false)->change();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->smallInteger('failed_login_attempts')->default(0)->change();
        });
    }
};
