<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 10 — deposit product master, deposit accounts, liens, and the
 * generic "other accounts" register. Also enforces the voucher_lines
 * forward FK declared as a plain column in Step 3A.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposit_products', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique(); // SAVINGS | CSAV | DPS | FDR | LTS …
            $table->string('name_en');
            $table->string('name_bn')->nullable();
            $table->boolean('is_compulsory')->default(false);
            $table->decimal('default_profit_rate', 8, 2)->default(0);
            $table->decimal('minimum_balance', 18, 2)->default(0);
            $table->decimal('minimum_opening_amount', 18, 2)->default(0);
            $table->json('withdrawal_rules')->nullable();
            $table->unsignedInteger('maturity_months')->nullable(); // DPS/FDR
            $table->decimal('premature_penalty_rate', 8, 2)->default(0);
            $table->boolean('auto_renew')->default(false); // FDR
            $table->string('gl_mapping_prefix', 60)->default('deposit.savings');
            // Product-specific structured data (e.g. the Long Term Savings
            // amount list, term table and premature slabs) — fully editable.
            $table->json('config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('deposit_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_number', 30)->unique();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('member_id')->constrained('members');
            $table->foreignId('deposit_product_id')->constrained('deposit_products');
            $table->string('title')->nullable();
            $table->text('address')->nullable();
            $table->string('phone', 30)->nullable();
            $table->date('opening_date');
            $table->decimal('profit_rate', 8, 2)->default(0); // snapshot from the product at opening
            $table->decimal('installment_amount', 18, 2)->nullable(); // DPS
            $table->date('maturity_date')->nullable(); // DPS/FDR, computed at opening
            $table->foreignId('nominee_id')->nullable()->constrained('nominees')->nullOnDelete();
            $table->decimal('credit_limit', 18, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->date('closing_date')->nullable();
            // Cached balances — source of truth is account_transactions (3A).
            $table->decimal('total_credit', 18, 2)->default(0);
            $table->decimal('total_debit', 18, 2)->default(0);
            $table->decimal('current_balance', 18, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['member_id', 'deposit_product_id']);
        });

        Schema::create('deposit_liens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deposit_account_id')->constrained('deposit_accounts')->cascadeOnDelete();
            // Plain columns by plan (round-3 fix): loan_proposals arrives in
            // Step 16 (FK added there); loan_accounts links at Step 17.
            $table->unsignedBigInteger('loan_proposal_id')->nullable();
            $table->unsignedBigInteger('loan_account_id')->nullable();
            $table->decimal('amount', 18, 2);
            $table->string('status', 20)->default('Active'); // Active | Released
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
        });

        Schema::create('other_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_type', 60);
            $table->string('name');
            $table->string('account_number', 30)->unique();
            $table->date('opening_date');
            $table->decimal('balance', 18, 2)->default(0);
            $table->text('comments')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Forward FK declared in Step 3A, enforced now that the table exists.
        // No SET NULL: the column sits in 3A's chk_voucher_lines_one_account
        // CHECK constraint, and MySQL forbids referential actions on such
        // columns (error 3823) — plain RESTRICT is also the safer accounting
        // semantic for posted voucher lines.
        Schema::table('voucher_lines', function (Blueprint $table) {
            $table->foreign('deposit_account_id')->references('id')->on('deposit_accounts');
        });
    }

    public function down(): void
    {
        Schema::table('voucher_lines', function (Blueprint $table) {
            $table->dropForeign(['deposit_account_id']);
        });
        Schema::dropIfExists('other_accounts');
        Schema::dropIfExists('deposit_liens');
        Schema::dropIfExists('deposit_accounts');
        Schema::dropIfExists('deposit_products');
    }
};
