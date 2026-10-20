<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Branch scoping (Step 2): users see only assigned branches;
        // include_children rolls assignment down the branch tree.
        Schema::create('branch_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->boolean('include_children')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'branch_id']);
        });

        // Samity scoping: samities arrive in Step 7, so samity_id stays a
        // plain column here (no FK until Step 7) — plan Step 2 build note.
        Schema::create('samity_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('samity_id');
            $table->timestamps();
            $table->unique(['user_id', 'samity_id']);
            $table->index('samity_id');
        });

        Schema::create('login_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->boolean('success')->default(false);
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_audits');
        Schema::dropIfExists('samity_user');
        Schema::dropIfExists('branch_user');
    }
};
