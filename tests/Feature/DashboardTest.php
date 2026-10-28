<?php

namespace Tests\Feature;

use App\Models\DepositAccount;
use App\Models\DepositProduct;
use App\Models\LoanAccount;
use App\Models\LoanProduct;
use App\Models\Member;
use App\Models\User;
use App\Services\BusinessDayService;
use App\Services\LoanMoneyService;
use App\Services\LoanOpeningService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Crud\CrudTestCase;

/**
 * Dashboard (Module 2 / Step 24): figures on a curated dataset, branch
 * filter, and role scoping. Expectations are computed from the source
 * rows (members / accounts / schedules / batches) — the same rows the
 * module lists and reports read — never from the dashboard itself.
 */
class DashboardTest extends CrudTestCase
{
    private int $memberSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->post('/branch/switch', ['branch_id' => $this->branch('102')->id])->assertRedirect();
    }

    private function biz(int $branchId): string
    {
        return app(BusinessDayService::class)->currentDate($branchId);
    }

    /** @return array<string, mixed> Inertia props of the dashboard page. */
    private function dashboardProps(string $url = '/'): array
    {
        $html = $this->get($url)->assertOk()->getContent();
        $found = preg_match('/<script data-page="app"[^>]*>(.*?)<\/script>/s', $html, $m);
        $this->assertSame(1, $found, 'Inertia shell carries the page JSON');
        $page = json_decode($m[1], true);
        $this->assertSame('Dashboard', $page['component']);

        return $page['props'];
    }

    private function makeMember(string $openDate): Member
    {
        $this->memberSeq++;
        $mobile = '0171400000'.$this->memberSeq;
        $this->post('/members', [
            'samity_id' => $this->samity()->id, 'name' => 'Dashboard Member '.$this->memberSeq,
            'gender' => 'Female', 'mobile' => $mobile, 'national_id' => '199730000'.$this->memberSeq,
            'share_amount' => 500, 'open_date' => $openDate,
            'nominee_name' => 'Nominee', 'nominee_dob' => '1990-01-01', 'nominee_relation' => 'Sister',
        ])->assertRedirect('/members/admission');

        return Member::where('mobile', $mobile)->firstOrFail();
    }

    private function openDeposit(Member $member, string $code, string $receive): DepositAccount
    {
        $product = DepositProduct::where('code', $code)->firstOrFail();
        $this->post('/deposits', [
            'member_id' => $member->id, 'deposit_product_id' => $product->id,
            'phone' => $member->mobile, 'opening_date' => $this->biz($this->branch('102')->id),
            'installment_amount' => $code === 'DPS' ? '500' : null,
        ])->assertRedirect('/deposits');
        $account = DepositAccount::where('member_id', $member->id)
            ->where('deposit_product_id', $product->id)->latest('id')->firstOrFail();
        $this->post('/deposits/receive', ['account_id' => $account->id, 'amount' => $receive])
            ->assertRedirect();

        return $account->fresh();
    }

    private function disbursedLoan(Member $member, string $biz): LoanAccount
    {
        $product = LoanProduct::where('code', '3001')->firstOrFail();
        $open = CarbonImmutable::parse($biz);
        $loan = app(LoanOpeningService::class)->open([
            'member_id' => $member->id, 'employee_id' => null, 'loan_proposal_id' => null,
            'loan_product_id' => $product->id, 'opening_date' => $open->subDays(70)->toDateString(),
            'loan_purpose' => 'Agriculture', 'loan_amount' => '12000', 'down_payment' => 0,
            'frequency' => 'M', 'no_of_installments' => 12, 'profit_rate' => null,
            'installment_from_date' => $open->subDays(65)->toDateString(), 'fees' => [], 'guarantors' => [],
        ], $this->admin);

        return app(LoanMoneyService::class)->disburse($loan, $this->admin);
    }

    /** Schedule-row truth: [total remaining, overdue remaining, overdue rows]. */
    private function scheduleTruth(LoanAccount $loan, string $biz): array
    {
        $rows = DB::table('loan_schedules')->where('loan_account_id', $loan->id)
            ->whereNull('superseded_by')->get();
        $total = '0.00';
        $overdue = '0.00';
        $overdueRows = 0;
        foreach ($rows as $r) {
            $remaining = bcsub(bcadd((string) $r->principal_due, (string) $r->profit_due, 2),
                bcadd((string) $r->paid_principal, (string) $r->paid_profit, 2), 2);
            $total = bcadd($total, $remaining, 2);
            if ($r->status !== 'Paid' && (string) $r->due_date < $biz) {
                $overdue = bcadd($overdue, $remaining, 2);
                $overdueRows++;
            }
        }

        return [$total, $overdue, $overdueRows];
    }

    public function test_dashboard_figures_move_with_curated_data(): void
    {
        $branch102 = $this->branch('102');
        $biz = $this->biz($branch102->id);

        $before = $this->dashboardProps()['kpis'];

        $m1 = $this->makeMember($biz);
        $m2 = $this->makeMember($biz);
        $savings = $this->openDeposit($m1, 'SAVINGS', '1000');
        $this->openDeposit($m2, 'DPS', '2000');
        $loan = $this->disbursedLoan($m1, $biz);
        [$expOutstanding, $expOverdue, $expOverdueRows] = $this->scheduleTruth($loan, $biz);
        $this->assertGreaterThan(0, $expOverdueRows, 'fixture loan must have overdue rows');

        // One member collection of 300 into m1's savings, on the business date.
        $this->post('/collection/member', [
            'member_id' => $m1->id,
            'lines' => [
                ['member_id' => $m1->id, 'account_type' => 'deposit', 'account_id' => $savings->id, 'amount' => '300'],
            ],
        ])->assertRedirect()->assertSessionHas('success');

        $after = $this->dashboardProps()['kpis'];

        $delta = fn (string $a, string $b) => bcsub($a, $b, 2);
        $this->assertSame($before['members']['total'] + 2, $after['members']['total']);
        $this->assertSame($before['members']['active'] + 2, $after['members']['active']);
        $this->assertSame($before['members']['new_month'] + 2, $after['members']['new_month']);
        $this->assertSame($before['deposit']['accounts'] + 2, $after['deposit']['accounts']);
        $this->assertSame('1300.00', $delta($after['deposit']['savings'], $before['deposit']['savings']));
        $this->assertSame('2000.00', $delta($after['deposit']['dps'], $before['deposit']['dps']));
        $this->assertSame('3300.00', $delta($after['deposit']['total'], $before['deposit']['total']));
        $this->assertSame($before['loan']['disbursed_count'] + 1, $after['loan']['disbursed_count']);
        $this->assertSame('12000.00', $delta($after['loan']['disbursed_amount'], $before['loan']['disbursed_amount']));
        $this->assertSame($expOutstanding, $delta($after['loan']['outstanding'], $before['loan']['outstanding']));
        $this->assertSame($expOverdue, $delta($after['loan']['overdue_amount'], $before['loan']['overdue_amount']));
        $this->assertSame($before['loan']['overdue_loans'] + 1, $after['loan']['overdue_loans']);
        // Collection figures follow the DCR definition (money actually
        // received): the two counter receives (1000 + 2000) plus the
        // 300 collection posting all land on the business date.
        $this->assertSame('3300.00', $delta($after['collection']['today'], $before['collection']['today']));
        $this->assertSame('3300.00', $delta($after['collection']['today_deposit'], $before['collection']['today_deposit']));
        $this->assertSame('3300.00', $delta($after['collection']['month'], $before['collection']['month']));

        // Samity + officer rows carry the same figures for Talgachi Samity.
        $props = $this->dashboardProps();
        $row = collect($props['samityRows'])->firstWhere('name', 'Talgachi Samity');
        $this->assertNotNull($row);
        $this->assertSame(Member::where('samity_id', $this->samity()->id)->where('status', 'Active')->count(), $row['members']);
        $officerTotal = collect($props['officerRows'])->sum(fn ($r) => (int) $r['members']);
        $this->assertSame(collect($props['samityRows'])->sum('members'), $officerTotal);
    }

    public function test_branch_filter_and_role_scoping(): void
    {
        $b101 = $this->branch('101');
        $b102 = $this->branch('102');

        $only102 = $this->dashboardProps('/?branch_id='.$b102->id)['kpis'];
        $this->assertSame(Member::where('branch_id', $b102->id)->count(), $only102['members']['total']);
        $only101 = $this->dashboardProps('/?branch_id='.$b101->id)['kpis'];
        $this->assertSame(Member::where('branch_id', $b101->id)->count(), $only101['members']['total']);
        $all = $this->dashboardProps()['kpis'];
        $this->assertSame($only101['members']['total'] + $only102['members']['total'], $all['members']['total']);

        // A branch-scoped user sees ONLY their branch, and the filter
        // cannot be used to peek at another branch.
        $manager = User::factory()->create(['must_change_password' => false]);
        $manager->assignRole('branch-manager');
        $manager->branches()->attach($b102->id);
        $this->actingAs($manager);
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);

        $scoped = $this->dashboardProps()['kpis'];
        $this->assertSame($only102['members']['total'], $scoped['members']['total']);
        $this->assertSame($only102['deposit']['total'], $scoped['deposit']['total']);
        $this->get('/?branch_id='.$b101->id)->assertForbidden();
    }

    public function test_dashboard_renders_zeroes_for_user_without_branches(): void
    {
        $plain = User::factory()->create(['must_change_password' => false]);
        $this->actingAs($plain);
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);

        $props = $this->dashboardProps();
        $this->assertSame(0, $props['kpis']['members']['total']);
        $this->assertSame('0.00', $props['kpis']['deposit']['total']);
        $this->assertSame([], $props['samityRows']);
    }
}
