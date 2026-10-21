<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 15 — loan masters: products, installment frequencies, EMI option templates. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_products', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name_en');
            $table->string('name_bn')->nullable();
            $table->decimal('profit_rate', 8, 2);
            $table->enum('rate_method', ['flat', 'declining'])->default('declining');
            $table->decimal('min_amount', 18, 2)->default(0);
            $table->decimal('max_amount', 18, 2)->default(0);
            $table->unsignedInteger('min_term')->default(1);
            $table->unsignedInteger('max_term')->default(100);
            $table->json('allowed_frequencies')->nullable();
            $table->json('penalty_rule')->nullable();
            $table->string('gl_mapping_prefix', 60)->default('loan.general');
            $table->unsignedInteger('min_cycle')->default(0);
            $table->unsignedInteger('max_cycle')->nullable();
            $table->decimal('min_savings_balance', 18, 2)->default(0);
            $table->unsignedInteger('max_concurrent_loans')->default(1);
            $table->unsignedInteger('min_member_months')->default(0);
            $table->json('fee_config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('installment_frequencies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('label_en');
            $table->string('label_bn')->nullable();
            $table->unsignedInteger('days_interval')->default(0);
            $table->timestamps();
        });

        Schema::create('emi_option_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_product_id')->constrained('loan_products')->cascadeOnDelete();
            $table->string('frequency', 20);
            $table->unsignedInteger('no_of_installments');
            $table->decimal('profit_rate', 8, 2);
            $table->text('comments')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emi_option_templates');
        Schema::dropIfExists('installment_frequencies');
        Schema::dropIfExists('loan_products');
    }
};
