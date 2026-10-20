<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Append-only enforcement at the database level (plan Step 1/B1).
     *
     * A table-level REVOKE cannot carve an exception out of the app
     * user's database-wide grant (MySQL error 1147), so immutability is
     * enforced with BEFORE UPDATE / BEFORE DELETE triggers that abort
     * every attempt — for every user, and recreated automatically by
     * migrate:fresh. Belt and braces: the AuditLog model also blocks
     * updates/deletes, and `db:apply-grants` documents the grant layer.
     */
    public function up(): void
    {
        DB::unprepared('
            CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'audit_logs is append-only\';
            END
        ');
        DB::unprepared('
            CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON audit_logs
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'audit_logs is append-only\';
            END
        ');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_delete');
    }
};
