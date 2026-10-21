<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 18 — loan installment receipts (product detail rows; the canonical
 * money record is the PostingService account_transactions row) plus the
 * per-schedule-row allocation detail needed for exact cancellation and
 * the installment history splits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_receives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_account_id')->constrained('loan_accounts');
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->date('receive_date');
            $table->date('business_date');
            $table->decimal('installment_amount', 18, 2); // cash taken in
            $table->decimal('investment_amount', 18, 2)->default(0); // principal applied
            $table->decimal('profit_amount', 18, 2)->default(0);
            $table->decimal('penalty_charge', 18, 2)->default(0);
            $table->boolean('transfer_from_deposit')->default(false);
            $table->foreignId('deposit_account_id')->nullable()->constrained('deposit_accounts')->nullOnDelete();
            $table->string('document_number')->nullable();
            $table->text('comments')->nullable();
            $table->foreignId('voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            // Extra (documented): the deposit-side voucher when the receipt
            // was funded by a deposit transfer, so cancel can reverse both.
            $table->foreignId('deposit_voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->foreignId('entry_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('entry_at')->nullable();
            $table->foreignId('cancel_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancel_at')->nullable();
            $table->timestamps();
        });

        Schema::create('loan_receive_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_receive_id')->constrained('loan_receives')->cascadeOnDelete();
            $table->foreignId('loan_schedule_id')->constrained('loan_schedules');
            $table->decimal('principal', 18, 2)->default(0);
            $table->decimal('profit', 18, 2)->default(0);
            $table->decimal('penalty', 18, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_receive_allocations');
        Schema::dropIfExists('loan_receives');
    }
};
