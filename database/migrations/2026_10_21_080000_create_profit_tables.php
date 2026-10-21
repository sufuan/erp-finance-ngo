<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 13 — deposit profit: setups, daily balance snapshots, distributions, payments, profiles. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profit_setups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deposit_account_id')->constrained('deposit_accounts');
            $table->date('effective_date');
            $table->unsignedInteger('serial')->default(1);
            $table->decimal('profit_rate', 8, 2);
            $table->foreignId('setup_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('setup_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('daily_balances', function (Blueprint $table) {
            $table->id();
            $table->enum('account_type', ['deposit', 'loan']);
            $table->unsignedBigInteger('account_id');
            $table->date('balance_date');
            $table->decimal('balance', 18, 2);
            $table->timestamps();
            $table->unique(['account_type', 'account_id', 'balance_date']);
        });

        Schema::create('profit_distributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('samity_id')->nullable()->constrained('samities')->nullOnDelete();
            $table->string('account_type', 20); // product code filter: SAVINGS/DPS/FDR/LTS/CSAV or ALL
            $table->date('from_date');
            $table->date('to_date');
            $table->string('profit_basis', 1); // D/B/M/X/A/O/N
            $table->decimal('profit_rate', 8, 2);
            $table->decimal('distribution_amount', 18, 2)->default(0);
            $table->date('posting_date');
            $table->boolean('exclude_zero_balance')->default(true);
            $table->enum('status', ['Draft', 'Calculated', 'Posted'])->default('Calculated');
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('profit_distribution_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('distribution_id')->constrained('profit_distributions')->cascadeOnDelete();
            $table->string('account_type', 20)->default('deposit');
            $table->unsignedBigInteger('account_id'); // deposit_accounts.id
            $table->decimal('balance', 18, 2);
            $table->decimal('profit_on_amount', 18, 2);
            $table->decimal('profit_pending', 18, 2)->default(0);
            $table->decimal('total_payable', 18, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('profit_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deposit_account_id')->constrained('deposit_accounts');
            $table->decimal('profit_amount', 18, 2);
            $table->date('payment_date');
            $table->text('comments')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('transaction_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deposit_account_id')->constrained('deposit_accounts');
            $table->decimal('installment_amount', 18, 2);
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();
            $table->text('comments')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_profiles');
        Schema::dropIfExists('profit_payments');
        Schema::dropIfExists('profit_distribution_lines');
        Schema::dropIfExists('profit_distributions');
        Schema::dropIfExists('daily_balances');
        Schema::dropIfExists('profit_setups');
    }
};
