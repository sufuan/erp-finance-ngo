<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Field withdrawal (client round 3, 2026-11-18): a collection batch
 * line can now be a WITHDRAWAL (payment) out of a member's general
 * savings account, posted beside the deposit at collection time.
 * `direction` marks the line ('receive' keeps the historical meaning
 * of every existing row); the batch caches the day's withdrawal total
 * in `deposit_payment`, mirroring `deposit_receive`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('collection_lines') && ! Schema::hasColumn('collection_lines', 'direction')) {
            Schema::table('collection_lines', function (Blueprint $table) {
                $table->string('direction', 10)->default('receive')->after('account_type');
            });
        }
        if (Schema::hasTable('collection_batches') && ! Schema::hasColumn('collection_batches', 'deposit_payment')) {
            Schema::table('collection_batches', function (Blueprint $table) {
                $table->decimal('deposit_payment', 14, 2)->default(0)->after('deposit_receive');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('collection_lines') && Schema::hasColumn('collection_lines', 'direction')) {
            Schema::table('collection_lines', function (Blueprint $table) {
                $table->dropColumn('direction');
            });
        }
        if (Schema::hasTable('collection_batches') && Schema::hasColumn('collection_batches', 'deposit_payment')) {
            Schema::table('collection_batches', function (Blueprint $table) {
                $table->dropColumn('deposit_payment');
            });
        }
    }
};
