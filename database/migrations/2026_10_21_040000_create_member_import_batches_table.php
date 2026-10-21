<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 9A — member migration batches. The staged rows (with their dry-run
 * verdicts) live in `rows`; per-row import failures land in `failures` and
 * power the downloadable error report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('samity_id')->nullable()->constrained('samities')->nullOnDelete(); // page-level default samity
            $table->string('source', 10)->default('excel'); // excel | grid
            $table->string('status', 20)->default('Preview'); // Preview | Queued | Processing | Completed
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->json('rows')->nullable();
            $table->json('failures')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_import_batches');
    }
};
