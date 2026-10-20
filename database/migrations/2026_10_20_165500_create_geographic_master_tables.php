<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Plan Step 4: Country → Division → District → Upazila → Union → Village → Working Area. */
    public function up(): void
    {
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
            $table->string('name'); // Union / Poroshova / City Corporation
            $table->foreignId('upazila_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
        Schema::create('villages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('union_id')->constrained()->cascadeOnDelete();
            $table->foreignId('upazila_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
        Schema::create('working_areas', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('division_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('district_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('upazila_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('union_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('village_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('working_areas');
        Schema::dropIfExists('villages');
        Schema::dropIfExists('unions');
        Schema::dropIfExists('upazilas');
        Schema::dropIfExists('districts');
        Schema::dropIfExists('divisions');
        Schema::dropIfExists('countries');
    }
};
