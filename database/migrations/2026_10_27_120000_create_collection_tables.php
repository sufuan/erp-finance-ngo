<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 21–23 — Collection module tables.
 *
 * collection_batches / collection_lines: one batch per posting action
 * (member-level, samity sheet, or simple sheet). One line per target
 * ACCOUNT (binding: no hard-coded cs/vs/loan1/loan2 columns — a member
 * may hold any number of deposit accounts and loans). Each line points
 * at the product detail row the money engines created
 * (deposit_transactions / loan_receives / the share voucher), so
 * cancellation delegates to those engines' latest-only rules.
 *
 * other_collections: branch-level miscellaneous receipts (Step 21),
 * posted through PostingService with the `collection.other` mapping.
 *
 * passbook_issues / cheque_book_issues: Step 23 member instruments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('samity_id')->nullable()->constrained('samities')->nullOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->enum('kind', ['member', 'sheet', 'simple'])->default('sheet');
            $table->date('tran_date');
            $table->date('business_date');
            $table->decimal('total_receivable', 18, 2)->default(0);
            $table->decimal('loan_receive', 18, 2)->default(0);
            $table->decimal('deposit_receive', 18, 2)->default(0);
            $table->decimal('share_receive', 18, 2)->default(0);
            $table->decimal('total_receive', 18, 2)->default(0);
            $table->decimal('total_dues', 18, 2)->default(0);
            $table->enum('status', ['Posted', 'Cancelled'])->default('Posted');
            $table->foreignId('entry_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('entry_at')->nullable();
            $table->foreignId('cancel_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancel_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'business_date']);
            $table->index(['samity_id', 'business_date']);
            $table->index(['member_id', 'business_date']);
        });

        Schema::create('collection_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('collection_batches')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members');
            $table->enum('account_type', ['deposit', 'loan', 'share']);
            // deposit_accounts.id / loan_accounts.id / members.id (share).
            $table->unsignedBigInteger('account_id');
            $table->string('deposit_product_code', 20)->nullable();
            $table->decimal('receivable', 18, 2)->default(0);
            $table->decimal('amount', 18, 2)->default(0);
            // The product detail row created by the money engine.
            $table->string('ref_type', 40)->nullable(); // deposit_transaction | loan_receive | voucher
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->enum('status', ['Posted', 'Cancelled'])->default('Posted');
            $table->foreignId('cancel_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancel_at')->nullable();
            $table->timestamps();
            $table->index(['account_type', 'account_id']);
        });

        Schema::create('other_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->string('collection_type');
            $table->string('collection_account')->nullable();
            $table->decimal('amount', 18, 2);
            $table->date('tran_date');
            $table->date('business_date');
            $table->string('document_number')->nullable();
            $table->text('comments')->nullable();
            $table->foreignId('voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->foreignId('entry_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('entry_at')->nullable();
            $table->foreignId('cancel_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancel_at')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'business_date']);
        });

        Schema::create('passbook_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('member_id')->constrained('members');
            $table->string('book_number');
            $table->decimal('fee_amount', 18, 2)->default(0);
            $table->date('issue_date');
            $table->date('business_date');
            $table->string('document_number')->nullable();
            $table->foreignId('voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->foreignId('entry_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('entry_at')->nullable();
            $table->foreignId('cancel_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancel_at')->nullable();
            $table->timestamps();
            $table->index(['member_id', 'issue_date']);
        });

        Schema::create('cheque_book_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('member_id')->constrained('members');
            $table->string('cheque_prefix')->nullable();
            $table->string('start_leaf');
            $table->string('end_leaf');
            $table->decimal('fee_amount', 18, 2)->default(0);
            $table->date('issue_date');
            $table->date('business_date');
            $table->string('document_number')->nullable();
            $table->foreignId('voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->foreignId('entry_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('entry_at')->nullable();
            $table->foreignId('cancel_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancel_at')->nullable();
            $table->timestamps();
            $table->index(['member_id', 'issue_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cheque_book_issues');
        Schema::dropIfExists('passbook_issues');
        Schema::dropIfExists('other_collections');
        Schema::dropIfExists('collection_lines');
        Schema::dropIfExists('collection_batches');
    }
};
