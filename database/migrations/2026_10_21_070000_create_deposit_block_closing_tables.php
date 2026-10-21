<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 12 — deposit transaction blocks and account closings. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposit_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('deposit_accounts');
            $table->date('block_effective_date');
            $table->decimal('block_amount', 18, 2);
            $table->text('reason');
            $table->boolean('deposit_block')->default(false);
            $table->boolean('withdrawal_block')->default(false);
            $table->foreignId('block_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('block_at')->nullable();
            $table->date('clear_date')->nullable();
            $table->decimal('clear_amount', 18, 2)->nullable();
            $table->text('clear_reason')->nullable();
            $table->foreignId('cleared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cleared_at')->nullable();
            $table->timestamps();
        });

        Schema::create('deposit_closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('deposit_accounts');
            $table->date('closing_date');
            $table->decimal('balance', 18, 2);
            $table->decimal('profit_payment', 18, 2)->default(0);
            $table->decimal('closing_charge', 18, 2)->default(0);
            $table->decimal('payable_amount', 18, 2);
            $table->text('reason')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_closings');
        Schema::dropIfExists('deposit_blocks');
    }
};
