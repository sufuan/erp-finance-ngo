<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Geography rework (owner order, 2026-10-21): divisions, districts,
     * upazilas and unions are NO LONGER database masters — the frontend
     * reads them from the `bd-geo-info` npm package (its async utils are
     * the dropdown API). This migration:
     *  - converts villages + working_areas geo references into PLAIN
     *    stored values (package id as string + name), backfilling the
     *    names from the old master tables before they are dropped;
     *  - drops the constrained geo FK columns on branches (added by
     *    2026_10_20_165501) and re-adds them as plain string columns that
     *    hold bd-geo-info package ids (no foreign keys);
     *  - drops the countries/divisions/districts/upazilas/unions tables.
     * Villages and working areas stay manual DB masters; the village_id
     * FK on working_areas is untouched.
     */
    public function up(): void
    {
        // --- villages: add plain name/id columns, backfill, drop FKs ----
        Schema::table('villages', function (Blueprint $table) {
            $table->string('division_id', 20)->nullable()->after('name');
            $table->string('division_name')->nullable()->after('division_id');
            $table->string('district_id', 20)->nullable()->after('division_name');
            $table->string('district_name')->nullable()->after('district_id');
            $table->string('upazila_name')->nullable()->after('upazila_id');
            $table->string('union_name')->nullable()->after('union_id');
        });
        DB::statement('UPDATE villages v
            LEFT JOIN unions u ON u.id = v.union_id
            LEFT JOIN upazilas up ON up.id = v.upazila_id
            LEFT JOIN districts d ON d.id = up.district_id
            LEFT JOIN divisions dv ON dv.id = d.division_id
            SET v.union_name = u.name, v.upazila_name = up.name,
                v.district_name = d.name, v.division_name = dv.name');
        Schema::table('villages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('union_id');
            $table->dropConstrainedForeignId('upazila_id');
        });
        Schema::table('villages', function (Blueprint $table) {
            $table->string('upazila_id', 20)->nullable()->after('district_name');
            $table->string('union_id', 20)->nullable()->after('upazila_name');
        });

        // --- working_areas: same treatment for its four geo FKs ---------
        Schema::table('working_areas', function (Blueprint $table) {
            $table->string('division_name')->nullable()->after('division_id');
            $table->string('district_name')->nullable()->after('district_id');
            $table->string('upazila_name')->nullable()->after('upazila_id');
            $table->string('union_name')->nullable()->after('union_id');
        });
        DB::statement('UPDATE working_areas w
            LEFT JOIN divisions dv ON dv.id = w.division_id
            LEFT JOIN districts d ON d.id = w.district_id
            LEFT JOIN upazilas up ON up.id = w.upazila_id
            LEFT JOIN unions u ON u.id = w.union_id
            SET w.division_name = dv.name, w.district_name = d.name,
                w.upazila_name = up.name, w.union_name = u.name');
        Schema::table('working_areas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('division_id');
            $table->dropConstrainedForeignId('district_id');
            $table->dropConstrainedForeignId('upazila_id');
            $table->dropConstrainedForeignId('union_id');
        });
        Schema::table('working_areas', function (Blueprint $table) {
            $table->string('division_id', 20)->nullable();
            $table->string('district_id', 20)->nullable();
            $table->string('upazila_id', 20)->nullable();
            $table->string('union_id', 20)->nullable();
        });

        // --- branches: FK ids out, plain package-id strings in ----------
        Schema::table('branches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('country_id');
            $table->dropConstrainedForeignId('division_id');
            $table->dropConstrainedForeignId('district_id');
            $table->dropConstrainedForeignId('upazila_id');
            $table->dropConstrainedForeignId('union_id');
        });
        Schema::table('branches', function (Blueprint $table) {
            $table->string('division_id', 20)->nullable()->after('union');
            $table->string('district_id', 20)->nullable()->after('division_id');
            $table->string('upazila_id', 20)->nullable()->after('district_id');
            $table->string('union_id', 20)->nullable()->after('upazila_id');
        });

        // --- drop the five master tables --------------------------------
        Schema::dropIfExists('unions');
        Schema::dropIfExists('upazilas');
        Schema::dropIfExists('districts');
        Schema::dropIfExists('divisions');
        Schema::dropIfExists('countries');
    }

    public function down(): void
    {
        // Recreate the five master tables (empty) with their original
        // shape, and restore constrained FK columns. Plain package-id
        // strings and backfilled names are discarded on rollback.
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('divisions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('country_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
        Schema::create('districts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('division_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
        Schema::create('upazilas', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
        Schema::create('unions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('upazila_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['division_id', 'district_id', 'upazila_id', 'union_id']);
        });
        Schema::table('branches', function (Blueprint $table) {
            $table->foreignId('country_id')->nullable()->after('union')->constrained()->nullOnDelete();
            $table->foreignId('division_id')->nullable()->after('country_id')->constrained()->nullOnDelete();
            $table->foreignId('district_id')->nullable()->after('division_id')->constrained()->nullOnDelete();
            $table->foreignId('upazila_id')->nullable()->after('district_id')->constrained()->nullOnDelete();
            $table->foreignId('union_id')->nullable()->after('upazila_id')->constrained()->nullOnDelete();
        });

        Schema::table('working_areas', function (Blueprint $table) {
            $table->dropColumn(['division_id', 'district_id', 'upazila_id', 'union_id',
                'division_name', 'district_name', 'upazila_name', 'union_name']);
        });
        Schema::table('working_areas', function (Blueprint $table) {
            $table->foreignId('division_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('district_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('upazila_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('union_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('villages', function (Blueprint $table) {
            $table->dropColumn(['division_id', 'division_name', 'district_id', 'district_name',
                'upazila_id', 'upazila_name', 'union_id', 'union_name']);
        });
        Schema::table('villages', function (Blueprint $table) {
            $table->foreignId('union_id')->constrained()->cascadeOnDelete();
            $table->foreignId('upazila_id')->constrained()->cascadeOnDelete();
        });
    }
};
