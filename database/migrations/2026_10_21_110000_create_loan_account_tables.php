<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 17 — loan accounts, guarantors, loan fees (one row per fee),
 * installment schedules, due penalty setups. Enforces the Step 3A
 * forward FK voucher_lines.loan_account_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('loan_number')->unique();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('loan_product_id')->constrained('loan_products');
            $table->foreignId('loan_proposal_id')->nullable()->constrained('loan_proposals')->nullOnDelete();
            $table->date('opening_date');
            $table->string('loan_purpose')->nullable();
            $table->decimal('loan_amount', 18, 2);
            $table->decimal('down_payment', 18, 2)->default(0);
            $table->boolean('block_account')->default(false);
            $table->string('frequency', 20);
            $table->unsignedInteger('no_of_installments');
            $table->decimal('installment_amount', 18, 2);
            $table->decimal('actual_installment_amount', 18, 2);
            $table->decimal('extra_installment_amount', 18, 2)->default(0);
            $table->decimal('last_installment_amount', 18, 2);
            $table->decimal('profit_rate', 8, 2);
            $table->decimal('profit_amount', 18, 2);
            $table->decimal('total_repayment', 18, 2);
            $table->date('installment_from_date');
            $table->date('installment_expire_date');
            $table->string('payment_type')->nullable();
            $table->string('payment_to_account')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable(); // plain — FK arrives in Step 35
            $table->string('invoice_number')->nullable();
            $table->enum('status', ['Pending', 'Approved', 'Opened', 'Disbursed', 'Active', 'Closed', 'WrittenOff'])->default('Opened');
            $table->boolean('installment_reminder')->default(false);
            $table->boolean('overdue_reminder')->default(false);
            $table->foreignId('disbursed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('disbursed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('guarantors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_account_id')->constrained('loan_accounts')->cascadeOnDelete();
            $table->string('name');
            $table->string('relation')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->text('national_id')->nullable(); // encrypted cast
            $table->string('national_id_hash', 64)->nullable()->index();
            $table->timestamps();
        });

        Schema::create('loan_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_account_id')->constrained('loan_accounts')->cascadeOnDelete();
            $table->enum('fee_type', ['form', 'health', 'risk', 'document', 'emi_locker', 'others']);
            $table->decimal('amount', 18, 2);
            $table->foreignId('ledger_id')->constrained('ledgers');
            $table->foreignId('voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('loan_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_account_id')->constrained('loan_accounts')->cascadeOnDelete();
            $table->unsignedInteger('installment_no');
            $table->date('due_date');
            $table->decimal('principal_due', 18, 2);
            $table->decimal('profit_due', 18, 2);
            $table->decimal('total_due', 18, 2);
            $table->decimal('paid_principal', 18, 2)->default(0);
            $table->decimal('paid_profit', 18, 2)->default(0);
            $table->decimal('paid_penalty', 18, 2)->default(0);
            $table->enum('status', ['Pending', 'Partial', 'Paid'])->default('Pending');
            $table->timestamps();
            $table->unique(['loan_account_id', 'installment_no']);
        });

        Schema::create('due_penalty_setups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_account_id')->constrained('loan_accounts')->cascadeOnDelete();
            $table->decimal('penal_charge', 18, 2)->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::table('voucher_lines', function (Blueprint $table) {
            $table->foreign('loan_account_id')->references('id')->on('loan_accounts');
        });
    }

    public function down(): void
    {
        Schema::table('voucher_lines', function (Blueprint $table) {
            $table->dropForeign(['loan_account_id']);
        });
        Schema::dropIfExists('due_penalty_setups');
        Schema::dropIfExists('loan_schedules');
        Schema::dropIfExists('loan_fees');
        Schema::dropIfExists('guarantors');
        Schema::dropIfExists('loan_accounts');
    }
};
