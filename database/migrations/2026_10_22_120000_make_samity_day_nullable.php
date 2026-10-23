<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Samity meeting schedule rework: a samity now meets EITHER weekly
 * (samity_day set, date_of_month null) OR monthly (date_of_month set,
 * samity_day null; date_of_month 0 = last day of the month). The weekly
 * day column must therefore accept NULL for monthly samities.
 */
return new class extends Migration
{
    private const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    public function up(): void
    {
        Schema::table('samities', function (Blueprint $table) {
            $table->enum('samity_day', self::DAYS)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Restore a day on monthly rows so the NOT NULL enum can come back.
        DB::table('samities')->whereNull('samity_day')->update(['samity_day' => 'Wednesday']);
        Schema::table('samities', function (Blueprint $table) {
            $table->enum('samity_day', self::DAYS)->nullable(false)->change();
        });
    }
};
