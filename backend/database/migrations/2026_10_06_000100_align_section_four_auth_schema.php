<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'password') && ! Schema::hasColumn('users', 'password_hash')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->renameColumn('password', 'password_hash');
            });
        } elseif (Schema::hasColumn('users', 'password') && Schema::hasColumn('users', 'password_hash')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('password');
            });
        }

        $userColumnsToDrop = array_values(array_filter(
            ['name', 'remember_token'],
            fn (string $column): bool => Schema::hasColumn('users', $column),
        ));
        if ($userColumnsToDrop !== []) {
            Schema::table('users', function (Blueprint $table) use ($userColumnsToDrop): void {
                $table->dropColumn($userColumnsToDrop);
            });
        }

        foreach (['addresses', 'verification_tokens'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            $timestampsToDrop = array_values(array_filter(
                ['created_at', 'updated_at'],
                fn (string $column): bool => Schema::hasColumn($tableName, $column),
            ));
            if ($timestampsToDrop !== []) {
                Schema::table($tableName, function (Blueprint $table) use ($timestampsToDrop): void {
                    $table->dropColumn($timestampsToDrop);
                });
            }
        }

        if (Schema::hasTable('verification_tokens')) {
            $indexes = Schema::getIndexes('verification_tokens');
            foreach ($indexes as $index) {
                if ($index['unique'] && $index['columns'] === ['user_id']) {
                    Schema::table('verification_tokens', function (Blueprint $table) use ($index): void {
                        $table->dropUnique($index['name']);
                    });
                }
            }

            $hasUserTypeUniqueIndex = collect(Schema::getIndexes('verification_tokens'))
                ->contains(fn (array $index): bool => $index['unique'] && $index['columns'] === ['user_id', 'type']);

            if (! $hasUserTypeUniqueIndex) {
                Schema::table('verification_tokens', function (Blueprint $table): void {
                    $table->unique(['user_id', 'type']);
                });
            }
        }
    }

    public function down(): void
    {
        throw new LogicException('The Section 4 auth schema migration cannot restore removed account data.');
    }
};
