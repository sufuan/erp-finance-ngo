<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // e.g. 101
            $table->string('name');
            $table->enum('type', ['Branch', 'Regional', 'Zonal', 'Head Office'])->default('Branch');
            $table->foreignId('parent_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            // FK to employees is added in Step 6 (declared forward dependency).
            $table->unsignedBigInteger('manager_id')->nullable();
            $table->date('opening_date')->nullable();
            // Plain-string address parts for now; geo FK columns arrive in Step 4.
            $table->string('country')->nullable();
            $table->string('division')->nullable();
            $table->string('district')->nullable();
            $table->string('upazila')->nullable();
            $table->string('union')->nullable();
            $table->string('manager_phone')->nullable();
            $table->text('address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->enum('status', ['Active', 'Inactive'])->default('Active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
