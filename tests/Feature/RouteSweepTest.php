<?php

namespace Tests\Feature;

use App\Models\BalanceImportBatch;
use App\Models\Branch;
use App\Models\Member;
use App\Models\MemberImportBatch;
use App\Models\ProfitDistribution;
use App\Models\Samity;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Route sweep (redesign verification, owner request "verify all"): as a
 * fully-permissioned admin, GET every web route that serves a page and
 * assert a 200 Inertia response whose component file exists — the
 * server-side half of the "no white screens anywhere" guarantee (the
 * client half is the app-wide ErrorBoundary + page shape guards).
 *
 * Excluded by design: POST-only routes; PDF routes
 * (reports/{deposits,loans}/{key}/pdf); file/download routes
 * (files/{path}, migration error-reports, storage/{path}); health/dev
 * endpoints (up, _inertia/*); auth-flow pages (login, totp/*) which
 * redirect by design once an admin is fully authenticated.
 */
class RouteSweepTest extends TestCase
{
    use DatabaseTransactions;

    public function test_every_page_route_renders_for_a_full_admin(): void
    {
        $admin = User::factory()->create(['must_change_password' => false]);
        $admin->assignRole('super-admin');
        $this->actingAs($admin);
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);

        $samity = Samity::where('name', 'Talgachi Samity')->firstOrFail();
        $this->post('/members', [
            'samity_id' => $samity->id, 'name' => 'Sweep Member', 'gender' => 'Female',
            'mobile' => '01711112222', 'national_id' => '9999887766', 'share_amount' => 500,
            'open_date' => '2026-10-21', 'nominee_name' => 'Sweep Nominee',
            'nominee_dob' => '1990-01-01', 'nominee_relation' => 'Sister',
        ])->assertRedirect('/members/admission');
        $member = Member::where('mobile', '01711112222')->firstOrFail();

        $memberBatch = MemberImportBatch::create([
            'created_by' => $admin->id, 'samity_id' => $samity->id, 'source' => 'grid',
            'status' => 'Preview', 'total_rows' => 0, 'rows' => [],
        ]);
        $balanceBatch = BalanceImportBatch::create([
            'created_by' => $admin->id, 'samity_id' => $samity->id, 'branch_id' => $samity->branch_id,
            'transaction_date' => '2026-10-21', 'add_members' => false, 'source' => 'grid',
            'status' => 'Preview', 'total_rows' => 0, 'rows' => [],
        ]);
        // The four loan adjustment forms are per-loan pages: they
        // findOrFail on ?loan_id= and 404 without one, by design.
        $loan = new \App\Models\LoanAccount;
        $loan->forceFill([
            'loan_number' => 'SWEEP-LOAN-1', 'branch_id' => $samity->branch_id,
            'member_id' => $member->id, 'loan_product_id' => \App\Models\LoanProduct::firstOrFail()->id,
            'opening_date' => '2026-10-21', 'loan_amount' => 10000, 'frequency' => 'M',
            'no_of_installments' => 12, 'installment_amount' => 958.33,
            'actual_installment_amount' => 958.33, 'last_installment_amount' => 958.37,
            'profit_rate' => 15, 'profit_amount' => 1500, 'total_repayment' => 11500,
            'installment_from_date' => '2026-11-01', 'installment_expire_date' => '2027-10-01',
            'status' => 'Active',
        ])->save();

        $distribution = new ProfitDistribution;
        $distribution->forceFill([
            'branch_id' => Branch::firstOrFail()->id, 'samity_id' => $samity->id,
            'account_type' => 'ALL', 'from_date' => '2026-10-01', 'to_date' => '2026-10-31',
            'profit_basis' => 'A', 'profit_rate' => 15, 'posting_date' => '2026-10-21',
        ])->save();

        $urls = [
            '/', '/hub/member', '/hub/deposit', '/hub/loan', '/hub/hrm', '/hub/settings',
            '/members', '/members/admission', '/members/closing', '/members/transfer',
            "/members/{$member->id}", "/members/{$member->id}/edit", "/members/{$member->id}/admission-form",
            '/deposits', '/deposits/blocks', '/deposits/blocks/clear', '/deposits/closing',
            '/deposits/opening', '/deposits/other', '/deposits/payment', '/deposits/products',
            '/deposits/profit/distribution', '/deposits/profit/distribution/auto',
            '/deposits/profit/distributions', "/deposits/profit/distributions/{$distribution->id}",
            '/deposits/profit/payment', '/deposits/profit/profile', '/deposits/profit/setup',
            '/deposits/receive', '/deposits/share', '/deposits/transfer',
            '/loans/disbursement', '/loans/due-penalty', '/loans/installment-settings',
            '/loans/insurance-claims', '/loans/opening', '/loans/products',
            '/loans/proposals', '/loans/proposals/create', '/loans/receive', '/loans/receive/cancel',
            '/loans/receive/history',
            "/loans/reschedule?loan_id={$loan->id}", "/loans/waiver?loan_id={$loan->id}",
            "/loans/penalty?loan_id={$loan->id}", "/loans/writeoff?loan_id={$loan->id}",
            '/reports/deposits', '/reports/loans', '/reports/llp',
            '/hrm/departments', '/hrm/designations', '/hrm/employee-types', '/hrm/shifts',
            '/settings/balance-migrations', "/settings/balance-migrations/{$balanceBatch->id}",
            '/settings/branches', '/settings/business-day', '/settings/directors',
            '/settings/employees', '/settings/general',
            '/settings/geo/countries', '/settings/geo/divisions', '/settings/geo/districts',
            '/settings/geo/upazilas', '/settings/geo/unions', '/settings/geo/villages',
            '/settings/geo/working-areas',
            '/settings/holidays',
            '/settings/member-migrations', "/settings/member-migrations/{$memberBatch->id}",
            '/settings/notices', '/settings/permissions', '/settings/roles', '/settings/samities',
            '/settings/samities/changes/day', '/settings/samities/changes/officer',
            '/settings/samities/changes/manager', '/settings/samities/merge', '/settings/samities/split',
            '/settings/users', "/settings/users/{$admin->id}/login-history",
            '/password/change',
        ];

        $failures = [];
        foreach ($urls as $url) {
            $response = $this->get($url);
            $page = $response->original instanceof \Illuminate\Contracts\View\View
                ? $response->viewData('page')
                : null;
            $component = $page['component'] ?? null;
            if ($response->status() !== 200) {
                $failures[] = "{$url} → HTTP {$response->status()}";
            } elseif (! is_string($component) || $component === '') {
                $failures[] = "{$url} → no Inertia component";
            } elseif (! file_exists(resource_path("js/Pages/{$component}.jsx"))) {
                $failures[] = "{$url} → missing page file for component {$component}";
            }
        }

        $this->assertSame([], $failures, "Route sweep failures:\n".implode("\n", $failures));
        $this->assertCount(82, $urls);
    }
}
