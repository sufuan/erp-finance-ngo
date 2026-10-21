<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 8 — members and their satellite tables. Portal credentials live
 * in member_users (username = mobile, never the sequential member_no).
 * share_amount on members is the canonical share capital.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->string('member_no', 20)->unique();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('samity_id')->constrained('samities');
            $table->string('name');
            $table->string('father_name')->nullable();
            $table->string('mother_name')->nullable();
            $table->string('spouse_name')->nullable();
            $table->enum('gender', ['Male', 'Female', 'Third Gender']);
            $table->string('religion')->nullable();
            $table->string('blood_group', 10)->nullable();
            $table->string('marital_status', 30)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('mobile', 11)->unique();
            $table->string('emergency_phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->text('national_id')->nullable(); // encrypted cast
            $table->char('nid_hash', 64)->nullable()->unique();
            $table->text('passport_no')->nullable(); // encrypted cast
            $table->char('passport_hash', 64)->nullable()->unique();
            $table->text('tin')->nullable(); // encrypted cast
            $table->string('occupation')->nullable();
            $table->string('education')->nullable();
            $table->text('present_address')->nullable();
            $table->text('permanent_address')->nullable();
            $table->decimal('share_amount', 18, 2)->default(0);
            $table->date('open_date');
            $table->string('profile_photo')->nullable();
            $table->string('status', 20)->default('Active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index(['samity_id', 'status']);
        });

        Schema::create('member_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->unique()->constrained('members')->cascadeOnDelete();
            $table->string('username')->unique();
            $table->string('password');
            $table->boolean('is_active')->default(false);
            $table->boolean('must_change_password')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->unsignedInteger('failed_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamps();
        });

        Schema::create('nominees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->string('name');
            $table->date('dob')->nullable();
            $table->text('address')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('relation', 50)->nullable();
            $table->string('photo')->nullable();
            $table->timestamps();
        });

        Schema::create('member_closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->date('closing_date');
            $table->string('closing_reason');
            $table->decimal('share_amount_at_closing', 18, 2)->default(0);
            $table->string('share_disposition', 20)->default('Forfeited'); // Refunded | Forfeited
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('member_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->date('visit_date');
            $table->text('info')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamp('check_in_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('member_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->string('doc_type', 50);
            $table->string('file_path');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('member_login_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->boolean('success')->default(false);
            $table->timestamps();
        });

        Schema::create('member_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('from_samity_id')->nullable()->constrained('samities')->nullOnDelete();
            $table->foreignId('to_samity_id')->nullable()->constrained('samities')->nullOnDelete();
            $table->foreignId('from_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('to_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->date('transfer_date');
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_transfers');
        Schema::dropIfExists('member_login_logs');
        Schema::dropIfExists('member_documents');
        Schema::dropIfExists('member_visits');
        Schema::dropIfExists('member_closings');
        Schema::dropIfExists('nominees');
        Schema::dropIfExists('member_users');
        Schema::dropIfExists('members');
    }
};
