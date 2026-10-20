<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Plan Step 5: HR lookup masters. */
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('name');
            $table->string('location')->nullable();
            // Plain column here — FK to employees arrives in Step 6.
            $table->unsignedBigInteger('incharge_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('designations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('employee_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('grace_minutes')->default(0);
            $table->time('refresh_start')->nullable();
            $table->time('refresh_end')->nullable();
            $table->time('overtime_start')->nullable();
            $table->time('overtime_end')->nullable();
            $table->unsignedSmallInteger('monthly_duty_days')->nullable();
            $table->unsignedSmallInteger('daily_duty_minutes')->nullable();
            $table->unsignedSmallInteger('allowed_late_days')->default(0);
            $table->decimal('default_bonus_percent', 5, 2)->default(0);
            $table->decimal('default_deduction_percent', 5, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
        Schema::dropIfExists('employee_types');
        Schema::dropIfExists('designations');
        Schema::dropIfExists('departments');
    }
};
