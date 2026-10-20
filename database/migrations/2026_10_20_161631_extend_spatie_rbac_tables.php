<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Step 2 §4.1: roles/permissions carry human labels for the matrix UI. */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('label')->nullable();
            $table->string('description')->nullable();
            $table->boolean('is_system')->default(false);
        });
        Schema::table('permissions', function (Blueprint $table) {
            $table->string('label')->nullable();
            $table->string('module')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('roles', fn (Blueprint $table) => $table->dropColumn(['label', 'description', 'is_system']));
        Schema::table('permissions', fn (Blueprint $table) => $table->dropColumn(['label', 'module']));
    }
};
