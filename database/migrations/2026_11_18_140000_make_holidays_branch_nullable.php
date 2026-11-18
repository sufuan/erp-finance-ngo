<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Holiday scope (owner order 2026-11-18): a holiday row with branch_id NULL
 * (and samity_id NULL) applies to ALL branches, so staff no longer have to
 * enter the same holiday once per branch. Branch-wise rows keep a branch_id
 * exactly as before. ScheduleEngine::isHoliday already treats a
 * both-NULL row as global.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('holidays') || ! Schema::hasColumn('holidays', 'branch_id')) {
            return;
        }

        $column = DB::selectOne(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['holidays', 'branch_id']
        );

        if ($column && $column->IS_NULLABLE === 'NO') {
            Schema::table('holidays', function (Blueprint $table) {
                $table->foreignId('branch_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('holidays') || ! Schema::hasColumn('holidays', 'branch_id')) {
            return;
        }

        // Global rows cannot go back to a NOT NULL branch column.
        DB::table('holidays')->whereNull('branch_id')->delete();

        $column = DB::selectOne(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['holidays', 'branch_id']
        );

        if ($column && $column->IS_NULLABLE === 'YES') {
            Schema::table('holidays', function (Blueprint $table) {
                $table->foreignId('branch_id')->nullable(false)->change();
            });
        }
    }
};
