<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 9 / Steps 26–30 — Accounts module screens' own tables.
 *
 * The posting engine (Step 3A) already owns ledgers / vouchers /
 * voucher_lines / account_transactions / cash_ledgers. These tables are
 * the SCREENS' registers and masters only; every taka still moves
 * exclusively through PostingService:
 *
 *  billing_types / receive_payment_setups — Account Management masters:
 *  named billing heads and the quick-posting presets (ledger + side)
 *  the Account Transaction form offers.
 *
 *  ledger_groups / ledger_group_lines / group_transactions — named
 *  multi-ledger posting templates and the register of vouchers posted
 *  from them.
 *
 *  recurring_setups — standing ledger-to-ledger amounts (rent, salary
 *  accrual…) with a due-posting workflow that posts real Journal
 *  vouchers and advances next_run_date.
 *
 *  fund_transfer_requests — inter-branch fund AND product transfers:
 *  request → sender approve (send voucher into Inter-Branch Clearing)
 *  → receiver receive (receive voucher out of clearing). Money is
 *  never created or destroyed; it parks in ledger 1130 in transit.
 *
 *  cash_transfers / cash_counts — officer→cashier handovers: a pending
 *  transfer moves nothing until the cashier confirms receipt (the
 *  counted amount may differ — shortage/excess is booked through a
 *  variance voucher). cash_counts is the day-end physical count
 *  against the cash sub-ledger's expected balance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_types', function (Blueprint $table) {
            $table->id();
            $table->string('name_en');
            $table->string('name_bn')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('receive_payment_setups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_type_id')->nullable()->constrained('billing_types')->nullOnDelete();
            $table->string('name');
            $table->foreignId('ledger_id')->constrained('ledgers');
            $table->enum('side', ['Receive', 'Payment']);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['side', 'is_active']);
        });

        Schema::create('ledger_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['branch_id', 'is_active']);
        });

        Schema::create('ledger_group_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('ledger_groups')->cascadeOnDelete();
            $table->foreignId('ledger_id')->constrained('ledgers');
            $table->enum('side', ['Debit', 'Credit']);
            $table->timestamps();
        });

        Schema::create('group_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('group_id')->constrained('ledger_groups');
            $table->foreignId('voucher_id')->constrained('vouchers');
            $table->foreignId('entry_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['branch_id', 'created_at']);
        });

        Schema::create('recurring_setups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches');
            $table->string('name');
            $table->foreignId('debit_ledger_id')->constrained('ledgers');
            $table->foreignId('credit_ledger_id')->constrained('ledgers');
            $table->decimal('amount', 18, 2);
            $table->enum('frequency', ['Daily', 'Weekly', 'Monthly']);
            $table->date('next_run_date');
            $table->date('last_run_date')->nullable();
            $table->foreignId('last_voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->foreignId('entry_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['branch_id', 'next_run_date', 'is_active']);
        });

        Schema::create('fund_transfer_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_no');
            $table->enum('kind', ['Fund', 'Product'])->default('Fund');
            $table->foreignId('from_branch_id')->constrained('branches');
            $table->foreignId('to_branch_id')->constrained('branches');
            $table->decimal('amount', 18, 2)->default(0);
            $table->string('item_name')->nullable();
            $table->decimal('quantity', 18, 2)->nullable();
            $table->string('comments')->nullable();
            $table->enum('status', ['Pending', 'Approved', 'Received', 'Rejected', 'Cancelled'])->default('Pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('send_voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->foreignId('receive_voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->timestamps();
            $table->index(['from_branch_id', 'status']);
            $table->index(['to_branch_id', 'status']);
        });

        Schema::create('cash_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('from_user_id')->constrained('users');
            $table->foreignId('to_user_id')->constrained('users');
            $table->decimal('amount', 18, 2);
            $table->decimal('counted_amount', 18, 2)->nullable();
            $table->enum('status', ['Pending', 'Received', 'Cancelled'])->default('Pending');
            $table->date('business_date');
            $table->foreignId('voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->foreignId('variance_voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->foreignId('entry_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->string('comments')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'status']);
            $table->index(['to_user_id', 'status']);
        });

        Schema::create('cash_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('user_id')->constrained('users');
            $table->date('business_date');
            $table->decimal('expected_balance', 18, 2);
            $table->decimal('counted_balance', 18, 2);
            $table->decimal('difference', 18, 2);
            $table->foreignId('entry_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['branch_id', 'user_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_counts');
        Schema::dropIfExists('cash_transfers');
        Schema::dropIfExists('fund_transfer_requests');
        Schema::dropIfExists('recurring_setups');
        Schema::dropIfExists('group_transactions');
        Schema::dropIfExists('ledger_group_lines');
        Schema::dropIfExists('ledger_groups');
        Schema::dropIfExists('receive_payment_setups');
        Schema::dropIfExists('billing_types');
    }
};
