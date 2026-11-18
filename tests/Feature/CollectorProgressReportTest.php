<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CollectionBatch;
use App\Models\LoanAccount;
use App\Models\LoanProduct;
use App\Models\LoanSchedule;
use App\Models\Member;
use App\Models\Samity;
use App\Models\User;
use App\Services\BusinessDayService;
use App\Services\LoanMoneyService;
use App\Services\LoanOpeningService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Collector Progress report (owner order 2026-11-15): the admin picks
 * Collection Reports → Collector Progress and sees, per collecting
 * USER, what fell due in his ticked samities, what he posted, and what
 * was missed; selecting the user opens the installment-by-installment
 * detail (Collected / Partial / Missed). A samity he is NOT ticked
 * for never leaks into his numbers.
 *
 * Hand-computed scenario (product 3001: 12,000 loan, monthly × 12,
 * every installment 1083.10 = principal 1000.00 + profit 83.10):
 *   member A (ticked samity)   — collector posts 500.00 → #1 Partial, missed 583.10
 *   member B (ticked samity)   — collector posts 1083.10 → #1 Collected, missed 0.00
 *   member C (ticked samity)   — nothing posted → #1 Missed, missed 1083.10
 *   member D (UNTICKED samity) — nothing posted → must never appear
 * Summary row for the collector: samities 1, due_count 3,
 * loan_due 3249.30, collected_count 1, loan_collected 1583.10,
 * deposit_collected 0.00, total_collected 1583.10, missed_count 2,
 * missed 1666.20, progress 1583.10 / 3249.30 = 48.72%.
 */
class CollectorProgressReportTest extends TestCase
{
    use DatabaseTransactions;

    private Branch $branch;

    private Samity $ticked;

    private Samity $unticked;

    private User $admin;

    private string $bizDate;

    private int $memberSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::where('code', '102')->firstOrFail();
        $this->ticked = Samity::withoutGlobalScopes()->where('name', 'Talgachi Samity')->firstOrFail();
        $this->unticked = Samity::withoutGlobalScopes()->where('name', 'Talgachi Mohila Samity')->firstOrFail();
        $this->admin = User::factory()->create(['must_change_password' => false]);
        $this->admin->assignRole('super-admin');
        $this->bizDate = app(BusinessDayService::class)->currentDate($this->branch->id);
        $this->asAdmin();
    }

    private function asAdmin(): void
    {
        $this->actingAs($this->admin);
        $this->withSession([
            'active_branch_id' => $this->branch->id,
            'totp_passed_at' => now()->toDateTimeString(),
        ]);
    }

    private function makeMember(Samity $samity): Member
    {
        $this->memberSeq++;
        $mobile = '0171500000'.$this->memberSeq;
        $this->asAdmin();
        $this->post('/members', [
            'samity_id' => $samity->id, 'name' => 'Progress Member '.$this->memberSeq,
            'gender' => 'Female', 'mobile' => $mobile, 'national_id' => '199720000'.$this->memberSeq,
            'share_amount' => 500, 'open_date' => $this->bizDate,
            'nominee_name' => 'Nominee', 'nominee_dob' => '1990-01-01', 'nominee_relation' => 'Sister',
        ])->assertRedirect('/members/admission');

        return Member::withoutGlobalScopes()->where('mobile', $mobile)->firstOrFail();
    }

    private function disbursedLoan(Member $member): LoanAccount
    {
        $product = LoanProduct::where('code', '3001')->firstOrFail();
        $loan = app(LoanOpeningService::class)->open([
            'member_id' => $member->id, 'employee_id' => null, 'loan_proposal_id' => null,
            'loan_product_id' => $product->id, 'opening_date' => $this->bizDate,
            'loan_purpose' => 'Agriculture', 'loan_amount' => '12000', 'down_payment' => 0,
            'frequency' => 'M', 'no_of_installments' => 12, 'profit_rate' => null,
            'installment_from_date' => $this->bizDate, 'fees' => [], 'guarantors' => [],
        ], $this->admin);

        return app(LoanMoneyService::class)->disburse($loan, $this->admin);
    }

    /** A collector whose only samity tick is the Talgachi samity. */
    private function makeCollector(): User
    {
        $this->asAdmin();
        $this->post('/settings/roles', [
            'name' => 'progress-collector', 'label' => 'Progress Collector',
            'description' => 'Collects loans in ticked samities',
            'permissions' => ['collection.view', 'collection.collect'],
        ])->assertRedirect()->assertSessionHas('success');
        $this->post('/settings/users', [
            'name' => 'Progress Collector', 'username' => 'progress.collector',
            'email' => 'progress.collector@tpda.example', 'password' => 'Password@123',
            'locale' => 'en', 'roles' => ['progress-collector'],
            'branch_id' => $this->branch->id, 'samity_ids' => [$this->ticked->id],
        ])->assertRedirect()->assertSessionHas('success');

        $user = User::where('username', 'progress.collector')->firstOrFail();
        $user->forceFill(['must_change_password' => false])->save();

        return $user;
    }

    private function asCollector(User $user): void
    {
        $this->actingAs($user);
        $this->withSession([
            'active_branch_id' => $this->branch->id,
            'totp_passed_at' => now()->toDateTimeString(),
        ]);
    }

    /** Read the Inertia page props from the rendered HTML shell. */
    private function inertiaProps(string $url): array
    {
        $html = $this->get($url)->assertOk()->getContent();
        $found = preg_match('/<script data-page="app"[^>]*>(.*?)<\/script>/s', $html, $m);
        $this->assertSame(1, $found, 'Inertia shell carries the page JSON');

        return json_decode($m[1], true)['props'] ?? [];
    }

    public function test_collector_progress_summary_and_detail(): void
    {
        $memberA = $this->makeMember($this->ticked);
        $memberB = $this->makeMember($this->ticked);
        $memberC = $this->makeMember($this->ticked);
        $memberD = $this->makeMember($this->unticked);
        $loanA = $this->disbursedLoan($memberA);
        $loanB = $this->disbursedLoan($memberB);
        $loanC = $this->disbursedLoan($memberC);
        $loanD = $this->disbursedLoan($memberD);

        // Fixture sanity: every loan's installment 1 is 1083.10, and the
        // report period [from, to] covers the batch business date and
        // installment 1's due date but NOT installment 2's due date.
        $first = LoanSchedule::where('loan_account_id', $loanA->id)->where('installment_no', 1)->firstOrFail();
        $second = LoanSchedule::where('loan_account_id', $loanA->id)->where('installment_no', 2)->firstOrFail();
        $this->assertSame('1083.10', (string) $first->total_due);
        $due1 = $first->due_date->toDateString();
        $from = min($this->bizDate, $due1);
        $to = max($this->bizDate, $due1);
        $this->assertGreaterThan($to, $second->due_date->toDateString(), 'Installment 2 must fall outside the period');

        $collector = $this->makeCollector();
        $this->asCollector($collector);
        // Since 2026-11-18 an installment collection must carry the
        // compulsory current-savings part too (each loan member holds
        // an auto-opened linked savings account) — both posts carry
        // 100 of savings, which the report counts as deposits.
        $this->post('/collection/collect-loan', ['loan_id' => $loanA->id, 'amount' => '500', 'savings_amount' => '100'])
            ->assertRedirect('/collection/collect-loan?loan_id='.$loanA->id)
            ->assertSessionHas('success', __('collection_posted'));
        $this->post('/collection/collect-loan', ['loan_id' => $loanB->id, 'amount' => '1083.10', 'savings_amount' => '100'])
            ->assertRedirect('/collection/collect-loan?loan_id='.$loanB->id)
            ->assertSessionHas('success', __('collection_posted'));
        $batch = CollectionBatch::where('entry_by', $collector->id)->latest('id')->firstOrFail();
        $this->assertSame($this->bizDate, $batch->business_date->toDateString(), 'Batches post on the open business date');

        $query = 'from_date='.$from.'&to_date='.$to;
        $this->asAdmin();

        // ---- MODE A: summary, no user selected ----
        $props = $this->inertiaProps('/collection/reports?report=collector_progress&run=1&'.$query);
        $this->assertSame(
            ['collector', 'samities', 'due_count', 'loan_due', 'collected_count', 'loan_collected', 'deposit_collected', 'total_collected', 'missed_count', 'missed', 'progress'],
            $props['columns']);
        // The users prop feeds the collector dropdown.
        $usernames = collect($props['users'])->pluck('username')->all();
        $this->assertContains('progress.collector', $usernames);

        $mine = collect($props['rows'])->filter(fn ($row) => str_contains($row[0], 'progress.collector'))->values();
        $this->assertCount(1, $mine, 'Exactly one summary row for the collector');
        $this->assertSame([
            'progress.collector — Progress Collector',
            '1',        // ticked samities in scope
            '3',        // installments due in period (A, B, C — never D)
            '3249.30',  // 3 × 1083.10
            '1',        // fully collected (B)
            '1583.10',  // 500.00 + 1083.10 posted by him
            '200.00',   // the two compulsory savings parts (2 × 100)
            '1783.10',  // 1583.10 loan + 200.00 deposits
            '2',        // missed installments (A partial, C unpaid)
            '1666.20',  // 583.10 + 1083.10
            '48.72%',   // 1583.10 / 3249.30
        ], $mine[0]);

        // ---- MODE B: detail for the selected user ----
        $props = $this->inertiaProps('/collection/reports?report=collector_progress&run=1&user_id='.$collector->id.'&'.$query);
        $this->assertSame(
            ['member', 'loan_number', 'installment_no', 'due_date', 'due', 'collected', 'missed', 'status'],
            $props['columns']);
        $this->assertCount(3, $props['rows'], 'Only the ticked samity\'s three due installments');
        $byMember = collect($props['rows'])->keyBy(fn ($row) => $row[0]);
        $label = fn (Member $m) => $m->member_no.' — '.$m->name;
        $this->assertSame(
            [$label($memberA), $loanA->loan_number, '1', $due1, '1083.10', '500.00', '583.10', 'Partial'],
            $byMember[$label($memberA)]);
        $this->assertSame(
            [$label($memberB), $loanB->loan_number, '1', $due1, '1083.10', '1083.10', '0.00', 'Collected'],
            $byMember[$label($memberB)]);
        $this->assertSame(
            [$label($memberC), $loanC->loan_number, '1', $due1, '1083.10', '0.00', '1083.10', 'Missed'],
            $byMember[$label($memberC)]);
        $this->assertArrayNotHasKey($label($memberD), $byMember->all(), 'Unticked samity installment must not appear');
        $this->assertSame(['Total', '', '', '', '3249.30', '1583.10', '1666.20', ''], $props['totalRow']);
    }
}
