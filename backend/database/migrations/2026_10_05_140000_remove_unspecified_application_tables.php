<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'api_tokens',
            'sessions',
            'cache',
            'cache_locks',
            'jobs',
            'job_batches',
            'failed_jobs',
            'password_reset_tokens',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        if (Schema::hasTable('verification_tokens')) {
            $columns = array_values(array_filter(
                ['attempts', 'available_at'],
                fn (string $column): bool => Schema::hasColumn('verification_tokens', $column),
            ));

            if ($columns !== []) {
                Schema::table('verification_tokens', function (Blueprint $table) use ($columns) {
                    $table->dropColumn($columns);
                });
            }
        }
    }

    public function down(): void {}
};
