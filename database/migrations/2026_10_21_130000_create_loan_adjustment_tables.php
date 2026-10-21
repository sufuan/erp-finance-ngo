<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 19 — reschedules, waivers, penalties, write-offs, recoveries, insurance claims. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_reschedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_account_id')->constrained('loan_accounts');
            $table->date('reschedule_date');
            $table->decimal('additional_profit', 18, 2)->default(0);
            $table->string('frequency', 20);
            $table->decimal('installment_amount', 18, 2);
            $table->unsignedInteger('no_of_installments');
            $table->date('first_installment_date');
            $table->date('installment_expire_date');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('loan_schedules', function (Blueprint $table) {
            $table->foreignId('superseded_by')->nullable()->after('status')
                ->constrained('loan_reschedules')->nullOnDelete();
        });

        Schema::create('loan_waivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_account_id')->constrained('loan_accounts');
            $table->date('waiver_date');
            $table->decimal('principal_waive', 18, 2)->default(0);
            $table->decimal('profit_waive', 18, 2)->default(0);
            $table->decimal('charge_waive', 18, 2)->default(0);
            $table->decimal('total_waiver', 18, 2);
            // Extra (documented): per-row due reductions, for exact cancel restore.
            $table->json('row_splits')->nullable();
            $table->text('comments')->nullable();
            $table->foreignId('entry_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('entry_at')->nullable();
            $table->foreignId('cancel_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancel_at')->nullable();
            $table->timestamps();
        });

        Schema::create('loan_penalties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_account_id')->constrained('loan_accounts');
            $table->date('entry_date');
            $table->decimal('additional_profit', 18, 2)->default(0);
            $table->decimal('penalty_amount', 18, 2)->default(0);
            // Where additional_profit landed, so cancel can remove it exactly.
            $table->foreignId('schedule_id')->nullable()->constrained('loan_schedules')->nullOnDelete();
            $table->text('comments')->nullable();
            $table->foreignId('entry_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('entry_at')->nullable();
            $table->foreignId('cancel_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancel_at')->nullable();
            $table->timestamps();
        });

        Schema::create('writeoffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_account_id')->constrained('loan_accounts');
            $table->date('writeoff_date');
            $table->decimal('total_amount', 18, 2);
            $table->decimal('principal', 18, 2)->default(0);
            $table->decimal('profit', 18, 2)->default(0);
            $table->decimal('charge', 18, 2)->default(0);
            $table->text('comments')->nullable();
            $table->foreignId('entry_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('entry_at')->nullable();
            $table->timestamps();
        });

        Schema::create('writeoff_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_account_id')->constrained('loan_accounts');
            $table->date('recovery_date');
            $table->decimal('total_recovery', 18, 2);
            $table->decimal('principal_recovery', 18, 2)->default(0);
            $table->decimal('profit_recovery', 18, 2)->default(0);
            $table->decimal('charge_recovery', 18, 2)->default(0);
            $table->text('comments')->nullable();
            $table->foreignId('entry_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('entry_at')->nullable();
            $table->timestamps();
        });

        Schema::create('insurance_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members');
            $table->foreignId('loan_account_id')->nullable()->constrained('loan_accounts')->nullOnDelete();
            $table->enum('claim_type', ['death', 'disability']);
            $table->date('claim_date');
            $table->decimal('amount', 18, 2);
            $table->json('documents')->nullable();
            $table->enum('status', ['Filed', 'Approved', 'Rejected', 'Paid'])->default('Filed');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insurance_claims');
        Schema::dropIfExists('writeoff_collections');
        Schema::dropIfExists('writeoffs');
        Schema::dropIfExists('loan_penalties');
        Schema::dropIfExists('loan_waivers');
        Schema::table('loan_schedules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('superseded_by');
        });
        Schema::dropIfExists('loan_reschedules');
    }
};
