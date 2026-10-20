<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('entity'); // member, voucher, invoice, employee, ...
            $table->string('prefix')->nullable();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['branch_id', 'entity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sequences');
    }
};
