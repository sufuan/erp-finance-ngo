<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 16 — loan proposals; enforces the deposit_liens.loan_proposal_id FK declared in Step 10. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('member_id')->constrained('members');
            $table->foreignId('samity_id')->nullable()->constrained('samities')->nullOnDelete();
            $table->foreignId('loan_product_id')->constrained('loan_products');
            $table->date('proposal_date');
            $table->string('loan_purpose')->nullable();
            $table->decimal('loan_amount', 18, 2);
            $table->foreignId('fdr_account_id')->nullable()->constrained('deposit_accounts')->nullOnDelete();
            $table->string('frequency', 20);
            $table->unsignedInteger('no_of_installments');
            $table->decimal('installment_amount', 18, 2);
            $table->decimal('profit_rate', 8, 2);
            $table->decimal('profit_amount', 18, 2);
            $table->decimal('total_repayment', 18, 2);
            $table->date('installment_from_date');
            $table->date('installment_expire_date');
            $table->json('guarantors')->nullable(); // carried into the Step 17 opening form
            $table->enum('status', ['Submitted', 'Approved', 'Rejected'])->default('Submitted');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::table('deposit_liens', function (Blueprint $table) {
            $table->foreign('loan_proposal_id')->references('id')->on('loan_proposals')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('deposit_liens', function (Blueprint $table) {
            $table->dropForeign(['loan_proposal_id']);
        });
        Schema::dropIfExists('loan_proposals');
    }
};
