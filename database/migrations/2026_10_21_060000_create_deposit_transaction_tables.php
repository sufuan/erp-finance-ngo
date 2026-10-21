<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 11 — deposit product detail rows + transfers. The canonical money
 * record is always the account_transactions row written by PostingService;
 * these tables are the product-facing detail that points at it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('account_id')->constrained('deposit_accounts');
            $table->foreignId('member_id')->constrained('members');
            $table->string('tran_type', 10); // Receive | Payment
            $table->string('product', 20); // Savings | DPS | FDR
            $table->decimal('amount', 18, 2);
            $table->date('tran_date');
            $table->date('business_date');
            $table->string('document_number', 60)->nullable();
            $table->text('comments')->nullable();
            $table->foreignId('voucher_id')->nullable()->constrained('vouchers');
            $table->foreignId('entry_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancel_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancel_at')->nullable();
            $table->timestamps();
            $table->index(['account_id', 'tran_type']);
        });

        Schema::create('deposit_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('from_account_id')->constrained('deposit_accounts');
            $table->foreignId('to_account_id')->constrained('deposit_accounts');
            $table->decimal('amount', 18, 2);
            $table->date('transfer_date');
            $table->string('document_number', 60)->nullable();
            $table->text('comments')->nullable();
            $table->foreignId('entry_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancel_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancel_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_transfers');
        Schema::dropIfExists('deposit_transactions');
    }
};
