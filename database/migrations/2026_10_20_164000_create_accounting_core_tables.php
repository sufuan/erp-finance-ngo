<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Plan Step 3A — the money foundation: chart of accounts, vouchers,
     * GL mappings, the canonical account_transactions sub-ledger, cash
     * sub-ledger, fiscal years, voucher types.
     */
    public function up(): void
    {
        Schema::create('ledgers', function (Blueprint $table) {
            $table->id();
            $table->string('ledger_code', 20)->unique();
            $table->string('name_en');
            $table->string('name_bn');
            $table->enum('ledger_group', ['Asset', 'Liability', 'Income', 'Expense', 'Equity']);
            $table->foreignId('parent_id')->nullable()->constrained('ledgers')->nullOnDelete();
            $table->boolean('is_control')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('voucher_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique(); // Receipt|Payment|Journal|Contra
            $table->boolean('require_authorization')->default(true);
            $table->string('label_en');
            $table->string('label_bn');
            $table->timestamps();
        });

        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('batch_number', 30)->unique();
            $table->string('voucher_type', 30);
            $table->date('transaction_date');
            $table->date('business_date');
            $table->decimal('total_amount', 18, 2);
            $table->text('comments')->nullable();
            $table->string('document_path')->nullable();
            $table->foreignId('entry_by')->constrained('users');
            $table->timestamp('entry_at')->useCurrent();
            $table->foreignId('auth_by')->nullable()->constrained('users');
            $table->timestamp('auth_at')->nullable();
            $table->foreignId('cancel_by')->nullable()->constrained('users');
            $table->timestamp('cancel_at')->nullable();
            $table->enum('status', ['Pending', 'Authorized', 'Cancelled'])->default('Pending');
            $table->timestamps();
            $table->index(['branch_id', 'business_date']);
        });

        Schema::create('voucher_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ledger_id')->constrained('ledgers');
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            // Plain columns in this step — FKs arrive in Steps 10 / 17.
            $table->unsignedBigInteger('deposit_account_id')->nullable();
            $table->unsignedBigInteger('loan_account_id')->nullable();
            $table->string('comments')->nullable();
            $table->string('document_number')->nullable();
            $table->timestamps();
            $table->index(['deposit_account_id']);
            $table->index(['loan_account_id']);
        });
        // At most one account tag per line.
        DB::statement('ALTER TABLE voucher_lines ADD CONSTRAINT chk_voucher_lines_one_account CHECK (NOT (deposit_account_id IS NOT NULL AND loan_account_id IS NOT NULL))');

        Schema::create('gl_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('mapping_key', 100)->unique();
            $table->foreignId('debit_ledger_id')->constrained('ledgers');
            $table->foreignId('credit_ledger_id')->constrained('ledgers');
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('account_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->enum('account_type', ['deposit', 'loan', 'cash', 'share']);
            $table->unsignedBigInteger('account_id'); // deposit_accounts.id / loan_accounts.id / users.id (cash) / members.id (share)
            $table->string('tran_code', 30);
            $table->decimal('amount', 18, 2); // signed: + credit to the account holder
            $table->decimal('balance_after', 18, 2);
            $table->date('tran_date');
            $table->date('business_date');
            $table->foreignId('voucher_id')->constrained();
            $table->string('ref_type')->nullable();
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->foreignId('entry_by')->constrained('users');
            $table->timestamp('entry_at')->useCurrent();
            $table->foreignId('cancel_by')->nullable()->constrained('users');
            $table->timestamp('cancel_at')->nullable();
            $table->timestamps();
            // Idempotency: one movement per voucher per account per tran_code.
            $table->unique(['account_type', 'account_id', 'voucher_id', 'tran_code'], 'uq_account_transactions_idem');
            $table->index(['account_type', 'account_id', 'business_date']);
        });

        Schema::create('cash_ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->decimal('current_balance', 18, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('name', 20);
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['Open', 'Closed'])->default('Open');
            $table->timestamps();
            $table->unique(['branch_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_years');
        Schema::dropIfExists('cash_ledgers');
        Schema::dropIfExists('account_transactions');
        Schema::dropIfExists('gl_mappings');
        Schema::dropIfExists('voucher_lines');
        Schema::dropIfExists('vouchers');
        Schema::dropIfExists('voucher_types');
        Schema::dropIfExists('ledgers');
    }
};
