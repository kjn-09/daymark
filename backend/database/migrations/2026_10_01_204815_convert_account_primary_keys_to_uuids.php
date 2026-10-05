<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('users') || ! in_array(Schema::getColumnType('users', 'id'), ['integer', 'bigint', 'int'], true)) {
            return;
        }

        $users = DB::table('users')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        $addresses = DB::table('addresses')->get()->map(fn (object $row): array => (array) $row)->all();
        $verificationTokens = DB::table('verification_tokens')->get()->map(fn (object $row): array => (array) $row)->all();

        $userIds = [];
        foreach ($users as $user) {
            $userIds[$user['id']] = (string) Str::uuid();
        }

        Schema::disableForeignKeyConstraints();

        try {
            DB::transaction(function () use ($users, $addresses, $verificationTokens, $userIds): void {
                foreach (['verification_tokens', 'addresses', 'users'] as $table) {
                    Schema::dropIfExists($table);
                }

                $this->createUuidTables();

                foreach ($users as $user) {
                    $user['id'] = $userIds[$user['id']];
                    DB::table('users')->insert($user);
                }

                foreach ($addresses as $address) {
                    $address['id'] = (string) Str::uuid();
                    $address['user_id'] = $userIds[$address['user_id']];
                    DB::table('addresses')->insert($address);
                }

                foreach ($verificationTokens as $token) {
                    $token['id'] = (string) Str::uuid();
                    $token['user_id'] = $userIds[$token['user_id']];
                    $token['type'] = 'mobile_otp';
                    unset($token['attempts'], $token['available_at']);
                    DB::table('verification_tokens')->insert($token);
                }
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('UUID account keys cannot be safely converted back to integer keys.');
    }

    private function createUuidTables(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('first_name', 50);
            $table->string('last_name', 50);
            $table->string('middle_initial', 2)->nullable();
            $table->date('birthday');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('mobile_number', 20)->unique();
            $table->boolean('mobile_verified')->default(false);
            $table->unsignedTinyInteger('failed_login_attempts')->default(0);
            $table->boolean('is_locked')->default(false);
            $table->timestamp('locked_until')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('addresses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('house_street');
            $table->string('country', 100);
            $table->string('city', 100);
            $table->string('state', 100);
            $table->string('zip_code', 20);
            $table->timestamps();
        });

        Schema::create('verification_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64);
            $table->enum('type', ['email_verify', 'mobile_otp', 'password_reset', 'account_unlock']);
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }
};
