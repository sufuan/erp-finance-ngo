<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DepositAccount;
use App\Models\DepositProduct;
use App\Models\LoanAccount;
use App\Models\LoanProduct;
use App\Models\Member;
use App\Models\Samity;
use App\Models\User;
use App\Services\LoanMoneyService;
use App\Services\LoanOpeningService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Member 360 profile (owner order, 2026-11-20) — the profile page
 * must be a REAL profile: under the biodata it shows the member's
 * savings accounts with balances, his loans with paid/outstanding
 * figures, and his recent money activity. It must NOT present a
 * "username" for the member: a Member is a customer, never a login
 * account (the member_users row is a future portal credential whose
 * username defaults to the mobile number — displaying it as the
 * member's username was the bug).
 */
class MemberProfileTest extends TestCase
{
    use DatabaseTransactions;

    private Branch $branch;

    private Samity $samity;

    private User $admin;

    private int $memberSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::where('code', '102')->firstOrFail();
        $this->samity = Samity::withoutGlobalScopes()->where('name', 'Talgachi Samity')->firstOrFail();
        $this->admin = User::factory()->create(['must_change_password' => false]);
        $this->admin->assignRole('super-admin');
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

    /** Admit through the real form (account choice in $extra). */
    private function admit(array $extra = []): Member
    {
        $this->memberSeq++;
        $mobile = '0171900000'.$this->memberSeq;
        $this->asAdmin();
        $this->post('/members', array_merge([
            'samity_id' => $this->samity->id, 'name' => 'Profile Member '.$this->memberSeq,
            'gender' => 'Female', 'mobile' => $mobile, 'national_id' => '199870000'.$this->memberSeq,
            'share_amount' => 500, 'open_date' => '2026-10-21',
            'nominee_name' => 'Nominee', 'nominee_dob' => '1990-01-01', 'nominee_relation' => 'Sister',
        ], $extra))->assertRedirect('/members/admission');

        return Member::withoutGlobalScopes()->where('mobile', $mobile)->firstOrFail();
    }

    private function account(Member $member, string $code): ?DepositAccount
    {
        $product = DepositProduct::where('code', $code)->firstOrFail();

        return DepositAccount::where('member_id', $member->id)
            ->where('deposit_product_id', $product->id)->orderBy('id')->first();
    }

    private function disbursedLoan(Member $member): LoanAccount
    {
        $product = LoanProduct::where('code', '3001')->firstOrFail();
        $loan = app(LoanOpeningService::class)->open([
            'member_id' => $member->id, 'employee_id' => null, 'loan_proposal_id' => null,
            'loan_product_id' => $product->id, 'opening_date' => '2026-10-21',
            'loan_purpose' => 'Agriculture', 'loan_amount' => '12000', 'down_payment' => 0,
            'frequency' => 'M', 'no_of_installments' => 12, 'profit_rate' => null,
            'installment_from_date' => '2026-11-01', 'fees' => [], 'guarantors' => [],
        ], $this->admin);

        return app(LoanMoneyService::class)->disburse($loan, $this->admin);
    }

    public function test_profile_shows_accounts_loans_and_recent_activity_without_a_username(): void
    {
        $member = $this->admit([
            'account_type' => 'both', 'savings_amount' => 20,
            'lts_amount' => 100, 'lts_term_years' => 1,
        ]);
        $savings = $this->account($member, 'SAVINGS');
        $lts = $this->account($member, 'LTS');
        $this->assertNotNull($savings);
        $this->assertNotNull($lts);

        // A savings deposit through the unified Collection screen.
        $this->asAdmin();
        $this->post('/collection/collect-loan', [
            'member_id' => $member->id, 'savings_amount' => '20',
        ])->assertRedirect('/collection/collect-loan?member_id='.$member->id);

        // A disbursed loan: the 10% linked credit (1200) lands on the
        // SAME general savings account.
        $loan = $this->disbursedLoan($member);

        // One installment, collected with the compulsory savings.
        $due = null;
        $this->asAdmin();
        $this->get('/collection/collect-loan?member_id='.$member->id)->assertOk()
            ->assertInertia(function (AssertableInertia $p) use (&$due) {
                $due = $p->toArray()['props']['memberPanel']['loan']['due_amount'];
            });
        $this->assertSame('1083.10', $due);
        $this->asAdmin();
        $this->post('/collection/collect-loan', [
            'member_id' => $member->id, 'amount' => $due, 'savings_amount' => '50',
        ])->assertRedirect('/collection/collect-loan?member_id='.$member->id);

        $expectedOutstanding = app(LoanMoneyService::class)->outstanding($loan->fresh())['total'];
        $savingsProduct = DepositProduct::where('code', 'SAVINGS')->firstOrFail();

        $this->asAdmin();
        $this->get('/members/'.$member->id)->assertOk()
            ->assertInertia(function (AssertableInertia $p) use ($member, $savings, $lts, $loan, $expectedOutstanding, $savingsProduct) {
                $p->component('Members/Show')
                    ->where('member.member_no', $member->member_no)
                    // The fake username is gone from the payload.
                    ->missing('member.username')
                    // --- accounts: general first (opened first) ---
                    ->has('accounts', 2)
                    ->where('accounts.0.account_number', $savings->account_number)
                    ->where('accounts.0.product_code', 'SAVINGS')
                    ->where('accounts.0.product_name', $savingsProduct->localized_name)
                    ->where('accounts.0.status', 'Active')
                    // 20 deposit + 1200 loan-linked credit + 50
                    // compulsory savings collected with the installment.
                    ->where('accounts.0.balance', '1270.00')
                    ->where('accounts.0.installment_amount', '20.00')
                    ->where('accounts.0.passbook_url', '/collection/passbook?member='.$member->member_no.'&deposit_account_id='.$savings->id)
                    ->where('accounts.1.account_number', $lts->account_number)
                    ->where('accounts.1.product_code', 'LTS')
                    ->where('accounts.1.balance', '0.00')
                    ->where('accounts.1.installment_amount', '100.00')
                    // --- the loan ---
                    ->has('loans', 1)
                    ->where('loans.0.loan_number', $loan->loan_number)
                    ->where('loans.0.principal', '12000.00')
                    ->where('loans.0.total_payable', (string) $loan->total_repayment)
                    ->where('loans.0.total_paid', '1083.10')
                    ->where('loans.0.outstanding', $expectedOutstanding)
                    ->where('loans.0.paid_count', 1)
                    ->where('loans.0.total_installments', 12)
                    ->where('loans.0.status', 'Active')
                    ->where('loans.0.history_url', '/loans/receive/history?member='.$member->member_no.'&loan_id='.$loan->id);

                // --- recent activity: the installment and every
                // savings deposit appear with their references ---
                $activity = collect($p->toArray()['props']['activity']);
                $this->assertTrue(
                    $activity->contains(fn ($r) => $r['kind'] === 'installment_collection'
                        && $r['amount'] === '1083.10' && $r['reference'] === $loan->loan_number),
                    'Activity carries the collected installment'
                );
                foreach (['20.00', '1200.00', '50.00'] as $amount) {
                    $this->assertTrue(
                        $activity->contains(fn ($r) => $r['kind'] === 'savings_deposit'
                            && $r['amount'] === $amount && $r['reference'] === $savings->account_number),
                        "Activity carries the {$amount} savings deposit"
                    );
                }
                // Newest first: the last batch (installment + its
                // compulsory savings, posted in one transaction)
                // leads the list, and dates never increase downwards.
                $firstTwo = $activity->take(2)
                    ->map(fn ($r) => $r['kind'].' '.$r['amount'])->sort()->values()->all();
                $this->assertSame(['installment_collection 1083.10', 'savings_deposit 50.00'], $firstTwo);
                $dates = $activity->pluck('date')->all();
                $sorted = $dates;
                rsort($sorted);
                $this->assertSame($sorted, $dates);
            });
    }

    public function test_profile_of_a_member_without_accounts_or_loans_renders_empty_sections(): void
    {
        $member = $this->admit();

        $this->asAdmin();
        $this->get('/members/'.$member->id)->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Members/Show')
                ->missing('member.username')
                ->has('accounts', 0)
                ->has('loans', 0)
                ->has('activity', 0));

        // The member list payload no longer carries a username either.
        $this->asAdmin();
        $this->get('/members')->assertOk()
            ->assertInertia(function (AssertableInertia $p) {
                $rows = $p->toArray()['props']['members']['data'];
                $this->assertNotEmpty($rows);
                foreach ($rows as $row) {
                    $this->assertArrayNotHasKey('username', $row);
                }
            });
    }
}
