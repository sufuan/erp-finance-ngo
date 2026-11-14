<?php

namespace Tests\Feature;

use App\Models\LoanAccount;
use App\Models\LoanProduct;
use App\Models\Member;
use App\Models\Samity;
use App\Models\User;
use App\Services\BusinessDayService;
use App\Services\CollectionReportService;
use App\Services\LoanMoneyService;
use App\Services\LoanOpeningService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Crud\CrudTestCase;

/**
 * Director Dashboard (client order, 2026-11-14): the migration creates
 * the director.view permission + Director role; the page is view-only
 * for that role, its figures follow the DCR definition exactly, and a
 * director-only login lands on /director from the home route.
 */
class DirectorDashboardTest extends CrudTestCase
{
    private User $director;
    private int $memberSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->director = User::factory()->create(['must_change_password' => false]);
        $this->director->assignRole('director');
        // Owner rule (2026-11-14): samity visibility comes ONLY from the
        // user-form assignment, Director included — the admin ticks every
        // samity for the Director so his combined all-branch figures show.
        foreach (Samity::withoutGlobalScopes()->pluck('id') as $samityId) {
            DB::table('samity_user')->insertOrIgnore([
                'user_id' => $this->director->id, 'samity_id' => (int) $samityId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->post('/branch/switch', ['branch_id' => $this->branch('102')->id])->assertRedirect();
    }

    private function biz(int $branchId): string
    {
        return app(BusinessDayService::class)->currentDate($branchId);
    }

    /** @return array<string, mixed> Inertia props of the Director page. */
    private function directorProps(): array
    {
        $html = $this->actingAs($this->director)->get('/director')->assertOk()->getContent();
        $found = preg_match('/<script data-page="app"[^>]*>(.*?)<\/script>/s', $html, $m);
        $this->assertSame(1, $found, 'Inertia shell carries the page JSON');
        $page = json_decode($m[1], true);
        $this->assertSame('Director/Dashboard', $page['component']);

        return $page['props'];
    }

    /** @return array<string, mixed> The per-branch row for a branch code. */
    private function branchRow(array $props, string $code): array
    {
        $row = collect($props['branches'])->firstWhere('code', $code);
        $this->assertNotNull($row, "Director props carry branch {$code}");

        return $row;
    }

    /** DCR total row for one branch over [date, date]: [deposit, loan]. */
    private function dcrTotals(int $branchId, string $date): array
    {
        $dcr = app(CollectionReportService::class)->run('dcr_report', [
            '_branch_ids' => [$branchId], 'from_date' => $date, 'to_date' => $date,
        ]);

        return [(string) $dcr['total_row'][2], (string) $dcr['total_row'][3]];
    }

    public function test_migration_creates_permission_role_and_super_admin_grant(): void
    {
        $this->assertTrue(Permission::where('name', 'director.view')->exists());
        $director = Role::where('name', 'director')->first();
        $this->assertNotNull($director);
        $this->assertTrue((bool) $director->is_system);
        $this->assertSame(['director.view'], $director->permissions()->pluck('name')->all());
        $this->assertTrue(Role::where('name', 'super-admin')->first()->hasPermissionTo('director.view'));
        $this->assertFalse(Role::where('name', 'branch-manager')->first()->hasPermissionTo('director.view'));

        // Idempotent: running the migration again changes nothing.
        $migration = require database_path('migrations/2026_11_14_120000_add_director_role_and_permission.php');
        $migration->up();
        $this->assertSame(1, Role::where('name', 'director')->count());
        $this->assertSame(1, Permission::where('name', 'director.view')->count());
        $this->assertSame(['director.view'], Role::where('name', 'director')->first()
            ->permissions()->pluck('name')->all());
    }

    public function test_director_page_figures_match_dcr(): void
    {
        $branch = $this->branch('102');
        $biz = $this->biz($branch->id);

        $before = $this->branchRow($this->directorProps(), '102');
        [$dcrDepBefore, $dcrLoanBefore] = $this->dcrTotals($branch->id, $biz);

        // Fixture (as admin): a member admitted today + a loan disbursed
        // today whose first installment falls due today.
        $this->actingAs($this->admin);
        $member = $this->makeMember($biz);
        $product = LoanProduct::where('code', '3001')->firstOrFail();
        $loan = app(LoanOpeningService::class)->open([
            'member_id' => $member->id, 'employee_id' => null, 'loan_proposal_id' => null,
            'loan_product_id' => $product->id, 'opening_date' => $biz,
            'loan_purpose' => 'Agriculture', 'loan_amount' => '12000', 'down_payment' => 0,
            'frequency' => 'M', 'no_of_installments' => 12, 'profit_rate' => null,
            'installment_from_date' => $biz, 'fees' => [], 'guarantors' => [],
        ], $this->admin);
        $loan = app(LoanMoneyService::class)->disburse($loan, $this->admin);
        // disburse() stamps wall-clock now(); production business dates
        // track the calendar, so pin the fixture to the business date —
        // the disbursement register reads disbursed_at, like this page.
        $loan->update(['disbursed_at' => $biz.' 10:00:00']);
        // The seeded business date is a Saturday: the schedule engine
        // shifts generated due dates off Fri/Sat, so pin installment 1
        // onto the business date (a migrated schedule can look exactly
        // like this) to exercise the due-today figure.
        DB::table('loan_schedules')->where('loan_account_id', $loan->id)
            ->where('installment_no', 1)->update(['due_date' => $biz]);

        $mid = $this->branchRow($this->directorProps(), '102');
        $delta = fn (string $a, string $b) => bcsub($a, $b, 2);
        $this->assertSame('1', (string) ($mid['today']['new_members'] - $before['today']['new_members']));
        $this->assertSame('12000.00', $delta($mid['today']['disbursed'], $before['today']['disbursed']));
        $this->assertSame('1083.10', $delta($mid['today']['due_today'], $before['today']['due_today']));

        // A 500 loan collection on the business date (member collection).
        $this->actingAs($this->admin);
        $this->post('/collection/member', [
            'member_id' => $member->id,
            'lines' => [
                ['member_id' => $member->id, 'account_type' => 'loan', 'account_id' => $loan->id, 'amount' => '500'],
            ],
        ])->assertRedirect()->assertSessionHas('success');

        $after = $this->branchRow($this->directorProps(), '102');
        [$dcrDepAfter, $dcrLoanAfter] = $this->dcrTotals($branch->id, $biz);

        // The spot check: today's কিস্তি আদায় on the Director page IS
        // the DCR loan-receive total for the same branch and day.
        $this->assertSame($dcrLoanAfter, $after['today']['loan_collection']);
        $this->assertSame($dcrDepAfter, $after['today']['deposit_collection']);
        $this->assertSame('500.00', $delta($after['today']['loan_collection'], $mid['today']['loan_collection']));
        $this->assertSame('500.00', $delta($dcrLoanAfter, $dcrLoanBefore));
        // Paying 500 of the 1083.10 due today leaves 583.10 collectable.
        $this->assertSame('583.10', $delta($after['today']['due_today'], $before['today']['due_today']));
        // The combined (all-branch) figure is the sum of the branch rows.
        $props = $this->directorProps();
        $branchSum = collect($props['branches'])
            ->reduce(fn (string $s, $r) => bcadd($s, (string) $r['today']['loan_collection'], 2), '0.00');
        $this->assertSame($branchSum, $props['today']['loan_collection']);
    }

    public function test_user_without_director_view_gets_403(): void
    {
        $plain = User::factory()->create(['must_change_password' => false]);
        $this->actingAs($plain)->get('/director')->assertForbidden();
    }

    public function test_director_only_user_lands_on_director_dashboard(): void
    {
        $this->actingAs($this->director)->get('/')->assertRedirect('/director');
        // A user holding both permissions keeps the main dashboard.
        $this->actingAs($this->admin)->get('/')->assertOk();
    }

    private function makeMember(string $openDate): Member
    {
        $this->memberSeq++;
        $mobile = '0171500000'.$this->memberSeq;
        $this->post('/members', [
            'samity_id' => $this->samity()->id, 'name' => 'Director Member '.$this->memberSeq,
            'gender' => 'Female', 'mobile' => $mobile, 'national_id' => '199830000'.$this->memberSeq,
            'share_amount' => 500, 'open_date' => $openDate,
            'nominee_name' => 'Nominee', 'nominee_dob' => '1990-01-01', 'nominee_relation' => 'Sister',
        ])->assertRedirect('/members/admission');

        return Member::where('mobile', $mobile)->firstOrFail();
    }
}
