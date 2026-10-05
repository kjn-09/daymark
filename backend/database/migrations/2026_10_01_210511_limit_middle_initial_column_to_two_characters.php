<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $lengthFunction = DB::getDriverName() === 'sqlite' ? 'LENGTH' : 'CHAR_LENGTH';

        if (DB::table('users')->whereNotNull('middle_initial')->whereRaw("{$lengthFunction}(middle_initial) > 2")->exists()) {
            throw new RuntimeException('Normalize middle initials longer than two characters before applying this migration.');
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->string('middle_initial', 2)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('middle_initial', 3)->nullable()->change();
        });
    }
};
