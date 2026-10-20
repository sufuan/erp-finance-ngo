<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Plan Step 1 acceptance: "Attempted UPDATE audit_logs as the app DB user
 * fails (append-only)". Enforced by BEFORE UPDATE/DELETE triggers created
 * in the audit_logs trigger migration — they bind every DB user and are
 * recreated by migrate:fresh.
 */
class AuditAppendOnlyDbTest extends TestCase
{
    public function test_app_db_user_cannot_update_or_delete_audit_logs(): void
    {
        // Insert through the service so the hash chain stays valid, with a
        // unique action so re-runs never collide (rows cannot be cleaned).
        $action = 'trigger.test.'.uniqid();
        app(\App\Services\AuditService::class)->log(null, $action, 'test', 1);

        try {
            DB::statement("UPDATE audit_logs SET action = 'tampered' WHERE action = '{$action}'");
            $this->fail('UPDATE on audit_logs succeeded — append-only trigger missing');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        try {
            DB::statement("DELETE FROM audit_logs WHERE action = '{$action}'");
            $this->fail('DELETE on audit_logs succeeded — append-only trigger missing');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        // Cleanup is impossible by design; the row stays as evidence.
        $this->assertSame(1, DB::table('audit_logs')->where('action', $action)->count());
    }
}
