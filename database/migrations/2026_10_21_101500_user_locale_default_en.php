<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Default UI language is English (owner decision, 2026-10-21).
     * Existing users keep their saved locale; only new rows default to en.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE `users` ALTER `locale` SET DEFAULT 'en'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE `users` ALTER `locale` SET DEFAULT 'bn'");
    }
};
