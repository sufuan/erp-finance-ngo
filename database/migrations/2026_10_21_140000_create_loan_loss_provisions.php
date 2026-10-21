<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 20 — LLP runs (each posted run creates a provision journal). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_loss_provisions', function (Blueprint $table) {
            $table->id();
            $table->string('account_type')->nullable(); // loan product code, null = all
            $table->date('report_date');
            $table->decimal('outstanding', 18, 2)->default(0); // net basis total
            $table->decimal('required_llp', 18, 2)->default(0);
            $table->decimal('actual_llp', 18, 2)->default(0);   // provision balance after the run
            $table->decimal('coverage', 6, 2)->default(0);      // actual / required %
            $table->foreignId('voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_loss_provisions');
    }
};
