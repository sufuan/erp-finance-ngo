<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branch Manager Change history (client round 2026-11-18): mirrors
 * samity_manager_changes, one level up — the branch's manager
 * (branches.manager_id → employees) with change/effective dates.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('branch_manager_changes')) {
            return;
        }
        Schema::create('branch_manager_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->date('change_date');
            $table->date('effective_from');
            $table->string('previous_value')->nullable();
            $table->string('new_value');
            $table->text('comments')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_manager_changes');
    }
};
