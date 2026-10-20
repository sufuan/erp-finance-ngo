<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Forward FKs declared in Step 1, enforced here (plan Step 4).
     * The plain-string address columns stay as fallback; the string→FK
     * backfill runs in GeoSeeder (geo rows are seed data, so they don't
     * exist at migrate time).
     */
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->foreignId('country_id')->nullable()->after('union')->constrained()->nullOnDelete();
            $table->foreignId('division_id')->nullable()->after('country_id')->constrained()->nullOnDelete();
            $table->foreignId('district_id')->nullable()->after('division_id')->constrained()->nullOnDelete();
            $table->foreignId('upazila_id')->nullable()->after('district_id')->constrained()->nullOnDelete();
            $table->foreignId('union_id')->nullable()->after('upazila_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('country_id');
            $table->dropConstrainedForeignId('division_id');
            $table->dropConstrainedForeignId('district_id');
            $table->dropConstrainedForeignId('upazila_id');
            $table->dropConstrainedForeignId('union_id');
        });
    }
};
