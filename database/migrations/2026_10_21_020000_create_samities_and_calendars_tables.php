<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 7 — samities, change history, holidays, notices.
 * Also enforces the samity_user.samity_id FK declared in Step 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('samities', function (Blueprint $table) {
            $table->id();
            $table->string('samity_code', 12)->unique();
            $table->foreignId('branch_id')->constrained('branches');
            $table->string('name');
            $table->string('referred_by')->nullable();
            $table->text('address')->nullable();
            $table->date('open_date');
            $table->foreignId('working_area_id')->nullable()->constrained('working_areas')->nullOnDelete();
            $table->foreignId('field_officer_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('samity_phone', 20)->nullable();
            $table->enum('center_type', ['All Gender', 'Male', 'Female'])->default('All Gender');
            $table->time('start_time')->nullable();
            $table->enum('samity_day', ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']);
            $table->unsignedTinyInteger('date_of_month')->nullable();
            $table->foreignId('manager_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('status', 20)->default('Active');
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
        });

        foreach (['samity_day_changes', 'samity_officer_changes', 'samity_manager_changes'] as $changeTable) {
            Schema::create($changeTable, function (Blueprint $table) {
                $table->id();
                $table->foreignId('samity_id')->constrained('samities')->cascadeOnDelete();
                $table->date('change_date');
                $table->date('effective_from');
                $table->string('previous_value')->nullable();
                $table->string('new_value');
                $table->text('comments')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('samity_id')->nullable()->constrained('samities')->cascadeOnDelete();
            $table->date('holiday_date');
            $table->string('details');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'holiday_date']);
        });

        Schema::create('notices', function (Blueprint $table) {
            $table->id();
            $table->text('text');
            $table->enum('text_color', ['Red', 'Green', 'Yellow', 'Black'])->default('Black');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::table('samity_user', function (Blueprint $table) {
            $table->foreign('samity_id')->references('id')->on('samities')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('samity_user', fn (Blueprint $table) => $table->dropForeign(['samity_id']));
        Schema::dropIfExists('notices');
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('samity_manager_changes');
        Schema::dropIfExists('samity_officer_changes');
        Schema::dropIfExists('samity_day_changes');
        Schema::dropIfExists('samities');
    }
};
