<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 20A — balance migration batches + loan_receives.is_migrated flag. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('balance_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('samity_id')->nullable()->constrained('samities')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->date('transaction_date');
            $table->boolean('add_members')->default(false);
            $table->string('source')->default('excel'); // excel | grid
            $table->string('status')->default('DryRun'); // DryRun | Processing | Completed
            $table->integer('total_rows')->default(0);
            $table->integer('imported_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->json('rows')->nullable();
            $table->json('failures')->nullable();
            $table->timestamps();
        });

        Schema::table('loan_receives', function (Blueprint $table) {
            $table->boolean('is_migrated')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('loan_receives', function (Blueprint $table) {
            $table->dropColumn('is_migrated');
        });
        Schema::dropIfExists('balance_import_batches');
    }
};
