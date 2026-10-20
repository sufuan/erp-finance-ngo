<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Plan Step 2: login is by username; profile/locale/security columns. */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->after('name');
            // Staff login by username; email becomes optional contact data.
            $table->string('email')->nullable()->change();
            // Plain column in this step; FK to employees arrives in Step 6.
            $table->unsignedBigInteger('employee_id')->nullable()->after('username');
            $table->string('locale', 5)->default('bn')->after('employee_id');
            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            // TOTP (Step 2): stored encrypted via the model's `encrypted` cast.
            $table->text('totp_secret')->nullable();
            $table->timestamp('totp_confirmed_at')->nullable();
            // Lockout: 5 failed attempts -> 15 minutes (plan Step 2 auth rules).
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->unique('username');
            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn(['username', 'employee_id', 'locale', 'is_active', 'must_change_password', 'last_login_at', 'last_login_ip', 'totp_secret', 'totp_confirmed_at', 'failed_attempts', 'locked_until']);
            $table->string('email')->nullable(false)->change();
        });
    }
};
