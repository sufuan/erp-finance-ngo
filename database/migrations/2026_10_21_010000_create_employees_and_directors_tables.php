<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 6 — employees & directors registers.
 * NID/passport/TIN are stored encrypted (model casts); SHA-256 hash
 * columns power duplicate lookups without decrypting. Forward FKs
 * declared in Steps 1/2/5 (branches.manager_id, users.employee_id,
 * departments.incharge_id) are enforced here, now that employees exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_code', 12)->unique();
            $table->foreignId('branch_id')->constrained('branches');
            $table->string('name');
            $table->string('father_name')->nullable();
            $table->string('mother_name')->nullable();
            $table->enum('gender', ['Male', 'Female', 'Third Gender']);
            $table->date('joining_date');
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('designation_id')->nullable()->constrained('designations')->nullOnDelete();
            $table->foreignId('employee_type_id')->nullable()->constrained('employee_types')->nullOnDelete();
            $table->unsignedBigInteger('manager_id')->nullable();
            $table->text('present_address')->nullable();
            $table->text('permanent_address')->nullable();
            $table->date('dob')->nullable();
            $table->text('national_id')->nullable(); // encrypted cast
            $table->char('nid_hash', 64)->nullable()->unique();
            $table->text('passport_no')->nullable(); // encrypted cast
            $table->char('passport_hash', 64)->nullable()->unique();
            $table->text('tin')->nullable(); // encrypted cast
            $table->string('religion')->nullable();
            $table->string('blood_group', 10)->nullable();
            $table->string('education')->nullable();
            $table->string('marital_status', 30)->nullable();
            $table->string('mobile', 11)->unique();
            $table->string('emergency_mobile', 11)->nullable();
            $table->string('email')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->string('bank_account_name')->nullable();
            $table->string('bank_branch_name')->nullable();
            $table->string('branch_routing_number', 20)->nullable();
            $table->text('bank_comments')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('status', 20)->default('Active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('manager_id')->references('id')->on('employees')->nullOnDelete();
            $table->index(['branch_id', 'status']);
        });

        Schema::create('directors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('father_name')->nullable();
            $table->string('mother_name')->nullable();
            $table->enum('gender', ['Male', 'Female', 'Third Gender']);
            $table->text('present_address')->nullable();
            $table->text('permanent_address')->nullable();
            $table->date('dob')->nullable();
            $table->text('national_id')->nullable(); // encrypted cast
            $table->char('nid_hash', 64)->nullable()->unique();
            $table->text('passport_no')->nullable(); // encrypted cast
            $table->char('passport_hash', 64)->nullable()->unique();
            $table->text('tin')->nullable(); // encrypted cast
            $table->string('religion')->nullable();
            $table->string('blood_group', 10)->nullable();
            $table->string('education')->nullable();
            $table->string('marital_status', 30)->nullable();
            $table->string('mobile', 11)->unique();
            $table->string('emergency_mobile', 11)->nullable();
            $table->string('email')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->string('bank_account_name')->nullable();
            $table->string('bank_branch_name')->nullable();
            $table->string('branch_routing_number', 20)->nullable();
            $table->text('bank_comments')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('director_status', 20)->default('Active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        // Forward FKs declared earlier, enforced now.
        Schema::table('branches', function (Blueprint $table) {
            $table->foreign('manager_id')->references('id')->on('employees')->nullOnDelete();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('employee_id')->references('id')->on('employees')->nullOnDelete();
        });
        Schema::table('departments', function (Blueprint $table) {
            $table->foreign('incharge_id')->references('id')->on('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('departments', fn (Blueprint $table) => $table->dropForeign(['incharge_id']));
        Schema::table('users', fn (Blueprint $table) => $table->dropForeign(['employee_id']));
        Schema::table('branches', fn (Blueprint $table) => $table->dropForeign(['manager_id']));
        Schema::dropIfExists('directors');
        Schema::dropIfExists('employees');
    }
};
