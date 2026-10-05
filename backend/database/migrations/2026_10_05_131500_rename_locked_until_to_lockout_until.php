<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'locked_until') && ! Schema::hasColumn('users', 'lockout_until')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->renameColumn('locked_until', 'lockout_until');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'lockout_until') && ! Schema::hasColumn('users', 'locked_until')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->renameColumn('lockout_until', 'locked_until');
            });
        }
    }
};
