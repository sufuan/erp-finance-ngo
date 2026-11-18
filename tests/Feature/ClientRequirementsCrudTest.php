<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashLedger;
use App\Models\CollectionBatch;
use App\Models\DepositAccount;
use App\Models\DepositClosing;
use App\Models\DepositProduct;
use App\Models\LoanAccount;
use App\Models\LoanFee;
use App\Models\LoanProposal;
use App\Models\LoanSchedule;
use App\Models\Member;
use App\Models\Samity;
use App\Models\User;
use App\Services\BusinessDayService;
use App\Services\CollectionService;
use App\Services\LoanMoneyService;
use App\Services\PostingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Client requirements 2026-11-18 — the owner's CRUD GATE: THREE full
 * rounds with fresh realistic data each round, driven through the
 * real HTTP endpoints (the same forms the staff use). Each round:
 * samity + three member kinds (savings-only / LTS-only / loan with
 * auto savings) → proposal by field worker → approve by manager →
 * opening (insurance + custom fee) → disbursement split → picker
 * collection (compulsory rule + 3-part posting) → batch cancel and
 * re-post → office flows per round → trial balance.
 */
class ClientRequirementsCrudTest extends TestCase
{
    use DatabaseTransactions;

    private Branch $branch;

    private User $admin;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::where('code', '102')->firstOrFail();
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

    private function asUser(User $user): void
    {
        $this->actingAs($user);
        $this->withSession([
            'active_branch_id' => $this->branch->id,
            'totp_passed_at' => now()->toDateTimeString(),
        ]);
    }

    private function businessDate(): string
    {
        return app(BusinessDayService::class)->currentDate($this->branch->id);
    }

    private function makeSamity(Branch $branch, array $schedule): Samity
    {
        $this->seq++;
        $name = 'CRUD Samity R'.$this->seq;
        $this->post('/settings/samities', array_merge([
            'branch_id' => $branch->id, 'name' => $name, 'open_date' => '2026-10-01',
            'center_type' => 'All Gender',
        ], $schedule))->assertSessionHas('success');

        return Samity::withoutGlobalScopes()->where('name', $name)->firstOrFail();
    }

    private function makeMember(Samity $samity, string $kind): Member
    {
        $this->seq++;
        $mobile = '01720000'.str_pad((string) $this->seq, 3, '0', STR_PAD_LEFT);
        $this->post('/members', [
            'samity_id' => $samity->id, 'name' => 'CRUD '.$kind.' Member '.$this->seq,
            'gender' => 'Female', 'mobile' => $mobile, 'national_id' => '199880000'.$this->seq,
            'share_amount' => 500, 'open_date' => '2026-10-21',
            'nominee_name' => 'Nominee', 'nominee_dob' => '1990-01-01', 'nominee_relation' => 'Sister',
        ])->assertRedirect('/members/admission');

        return Member::withoutGlobalScopes()->where('mobile', $mobile)->firstOrFail();
    }

    private function makeRoleUser(string $slug, array $permissions, Samity $samity): User
    {
        $this->post('/settings/roles', [
            'name' => $slug, 'label' => ucwords(str_replace('-', ' ', $slug)),
            'permissions' => $permissions,
        ])->assertSessionHas('success');
        $username = str_replace('-', '', $slug);
        $this->post('/settings/users', [
            'name' => ucwords(str_replace('-', ' ', $slug)), 'username' => $username,
            'password' => 'Pass@12345', 'roles' => [$slug], 'branch_id' => $this->branch->id,
            'samity_ids' => [$samity->id],
        ])->assertSessionHas('success');
        $user = User::where('username', $username)->firstOrFail();
        $user->forceFill(['must_change_password' => false])->save();

        return $user;
    }

    private function openDeposit(Member $member, string $code, string $amount, ?int $termMonths = null): DepositAccount
    {
        $product = DepositProduct::where('code', $code)->firstOrFail();
        $payload = [
            'member_id' => $member->id, 'deposit_product_id' => $product->id,
            'phone' => $member->mobile, 'opening_date' => '2026-10-21', 'amount' => $amount,
        ];
        if ($termMonths !== null) {
            $payload['term_months'] = $termMonths;
        }
        $this->post('/deposits', $payload)->assertRedirect('/deposits');

        return DepositAccount::where('member_id', $member->id)
            ->where('deposit_product_id', $product->id)->orderByDesc('id')->firstOrFail();
    }

    private function accountOf(Member $member, string $code): ?DepositAccount
    {
        $product = DepositProduct::where('code', $code)->firstOrFail();

        return DepositAccount::where('member_id', $member->id)
            ->where('deposit_product_id', $product->id)->orderBy('id')->first();
    }

    /** The savings-only member journey: open, deposit, withdraw. */
    private function savingsOnlyJourney(Member $member, string $open, string $extra, string $withdraw): void
    {
        $account = $this->openDeposit($member, 'SAVINGS', $open);
        $this->post('/deposits/receive', ['account_id' => $account->id, 'amount' => $extra])
            ->assertSessionHas('success');
        $this->post('/deposits/payment', ['account_id' => $account->id, 'amount' => $withdraw])
            ->assertSessionHas('success');
        $expected = bcsub(bcadd($open, $extra, 2), $withdraw, 2);
        $this->assertSame($expected, (string) $account->fresh()->current_balance, 'Savings-only member balance after deposit + withdraw');
    }

    /** The LTS-only member journey: slab account, collects, LTS closing calc. */
    private function ltsOnlyJourney(Member $member, int $daily, int $years): void
    {
        $account = $this->openDeposit($member, 'LTS', (string) $daily, $years * 12);
        $this->post('/deposits/receive', ['account_id' => $account->id, 'amount' => $daily])
            ->assertSessionHas('success');
        $this->post('/deposits/receive', ['account_id' => $account->id, 'amount' => $daily])
            ->assertSessionHas('success');
        $deposited = number_format($daily * 3, 2, '.', '');
        $this->assertSame($deposited, (string) $account->fresh()->current_balance);

        // Close through the real closing form with a bogus typed
        // profit — the LTS engine figure (0% under one year) wins.
        $this->post('/deposits/closing', [
            'account_id' => $account->id, 'closing_date' => $this->businessDate(),
            'reason' => 'CRUD round closing', 'profit_payment' => '999',
        ])->assertSessionHas('success');
        $closing = DepositClosing::where('account_id', $account->id)->latest('id')->firstOrFail();
        $this->assertSame('0.00', (string) $closing->profit_payment, 'LTS premature (<1y) profit is 0 per the printed slabs');
        $this->assertSame($deposited, (string) $closing->payable_amount);
        $this->assertNotNull($account->fresh()->closing_date, 'Account is marked closed');
        $this->assertFalse((bool) $account->fresh()->is_active);
    }

    /**
     * The loan member journey shared by every round. The first
     * installment date is derived from the branch business date so
     * some installments are always due on collection day, whatever
     * date the seeded test database sits on.
     *
     * @param  array{amount:string, frequency:string, installments:int, customName:string, customAmount:string, savingsCollect:string, loanMemberLts:?array{0:int,1:int}, office:?string}  $cfg
     */
    private function loanJourney(Samity $samity, Member $member, array $cfg): void
    {
        $bd = \Carbon\Carbon::parse($this->businessDate());
        $from = match ($cfg['frequency']) {
            'M' => $bd->copy()->subMonth()->toDateString(),
            'W' => $bd->copy()->subDays(14)->toDateString(),
            default => $bd->copy()->subDays(6)->toDateString(),
        };
        $worker = $this->makeRoleUser('crud-worker-'.$this->seq, ['loans.view', 'loans.propose', 'collection.view', 'collection.collect'], $samity);
        $manager = $this->makeRoleUser('crud-manager-'.$this->seq, ['loans.view', 'loans.approve'], $samity);
        $product = \App\Models\LoanProduct::where('code', '3001')->firstOrFail();

        // Proposal by the field worker, approval by the manager.
        $this->asUser($worker);
        $this->post('/loans/proposals', [
            'member_id' => $member->id, 'loan_product_id' => $product->id,
            'loan_amount' => $cfg['amount'], 'frequency' => $cfg['frequency'],
            'no_of_installments' => $cfg['installments'], 'installment_from_date' => $from,
            'proposal_date' => $this->businessDate(), 'loan_purpose' => 'Small business',
        ])->assertSessionHas('success');
        $proposal = LoanProposal::where('member_id', $member->id)->latest('id')->firstOrFail();
        $this->asUser($manager);
        $this->post('/loans/proposals/'.$proposal->id.'/approve')->assertSessionHas('success');
        $this->assertSame('Approved', $proposal->fresh()->status);

        // Opening (admin) with the insurance fee + a named custom fee.
        $this->asAdmin();
        $insurance = number_format(((float) $cfg['amount']) * 0.01, 2, '.', '');
        $this->post('/loans/opening', [
            'member_id' => $member->id, 'loan_proposal_id' => $proposal->id,
            'loan_product_id' => $product->id, 'opening_date' => $this->businessDate(),
            'loan_purpose' => 'Small business', 'loan_amount' => $cfg['amount'],
            'down_payment' => 0, 'frequency' => $cfg['frequency'],
            'no_of_installments' => $cfg['installments'], 'installment_from_date' => $from,
            'fees' => ['form' => '20', 'insurance' => $insurance],
            'custom_fees' => [['name' => $cfg['customName'], 'amount' => $cfg['customAmount']]],
            'guarantors' => [],
        ])->assertSessionHas('success');
        $loan = LoanAccount::where('member_id', $member->id)->latest('id')->firstOrFail();

        // 1a: the linked savings account was auto-created.
        $savings = $this->accountOf($member, 'SAVINGS');
        $this->assertNotNull($savings, 'Auto savings account exists after opening');
        $this->assertSame('0.00', (string) $savings->current_balance);
        // 1c/1d: fee rows.
        $fees = LoanFee::where('loan_account_id', $loan->id)->get()->keyBy('fee_type');
        $this->assertSame($insurance, (string) $fees['insurance']->amount);
        $this->assertSame($cfg['customName'], $fees['custom']->label);
        $this->assertSame($cfg['customAmount'], (string) $fees['custom']->amount);

        // Optional LTS for the loan member (3-part collection).
        $lts = null;
        if ($cfg['loanMemberLts'] !== null) {
            $lts = $this->openDeposit($member, 'LTS', (string) $cfg['loanMemberLts'][0], $cfg['loanMemberLts'][1] * 12);
        }

        // Disbursement split (1b).
        $cashBefore = (string) CashLedger::where('user_id', $this->admin->id)->value('current_balance');
        $this->post('/loans/disbursement/'.$loan->id)->assertRedirect()->assertSessionHas('success');
        $expectedSavings = number_format(((float) $cfg['amount']) * 0.10, 2, '.', '');
        $this->assertSame($expectedSavings, (string) $savings->fresh()->current_balance, 'Savings credited 10% at disbursement');
        $cashAfter = (string) CashLedger::where('user_id', $this->admin->id)->value('current_balance');
        $this->assertSame(bcsub($cashBefore, bcsub($cfg['amount'], $expectedSavings, 2), 2), $cashAfter, 'Cash out = 90% only');
        $this->assertTrue(app(PostingService::class)->trialBalance($this->branch->id)['balanced']);

        // Collection through the picker (worker).
        $due = app(CollectionService::class)->loanReceivable($loan->fresh());
        $this->assertTrue(bccomp($due, '0', 2) > 0, 'Something is due on the business date');
        $this->asUser($worker);
        $this->get('/collection/collect-loan?samity_id='.$samity->id)
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('pickerMembers', function ($rows) use ($member) {
                $this->assertTrue(collect($rows)->contains('id', $member->id), 'Picker lists the loan member');

                return true;
            }));
        // Compulsory rule: installment alone is rejected.
        $this->post('/collection/collect-loan', [
            'loan_id' => $loan->id, 'amount' => $due,
        ])->assertSessionHasErrors('lines');
        // The 3-part collection posts atomically.
        $payload = ['loan_id' => $loan->id, 'amount' => $due, 'savings_amount' => $cfg['savingsCollect']];
        if ($lts !== null) {
            $payload['lts_amount'] = (string) $cfg['loanMemberLts'][0];
        }
        $savingsBefore = (string) $savings->fresh()->current_balance;
        $this->post('/collection/collect-loan', $payload)->assertRedirect()->assertSessionHas('success');
        $batch = CollectionBatch::where('branch_id', $this->branch->id)->latest('id')->firstOrFail();
        $this->assertSame($due, (string) $batch->loan_receive, 'Batch loan part = the due amount');
        $expectedDeposit = $lts !== null
            ? bcadd($cfg['savingsCollect'], (string) $cfg['loanMemberLts'][0], 2)
            : bcadd($cfg['savingsCollect'], '0', 2);
        $this->assertSame($expectedDeposit, (string) $batch->deposit_receive, 'Batch deposit part = savings (+ LTS)');
        if ($lts !== null) {
            $daily = (string) $cfg['loanMemberLts'][0];
            $this->assertSame(bcadd($daily, $daily, 2), (string) $lts->fresh()->current_balance, 'LTS credited by the collection (opening deposit + collected amount)');
        }
        $this->assertSame(bcadd($savingsBefore, $cfg['savingsCollect'], 2), (string) $savings->fresh()->current_balance);

        // Cancel the batch (admin) — everything reverses — then re-post.
        $paidAfterCollect = LoanSchedule::where('loan_account_id', $loan->id)->where('status', 'Paid')->count();
        $this->asAdmin();
        $this->post('/collection/batches/'.$batch->id.'/cancel', ['reason' => 'CRUD round cancel check'])
            ->assertSessionHas('success');
        $this->assertSame('Cancelled', $batch->fresh()->status);
        $this->assertSame($savingsBefore, (string) $savings->fresh()->current_balance, 'Batch cancel returns the savings money');
        $this->assertLessThan($paidAfterCollect, LoanSchedule::where('loan_account_id', $loan->id)->where('status', 'Paid')->count(), 'Batch cancel reverts installments');
        $this->asUser($worker);
        $this->post('/collection/collect-loan', $payload)->assertRedirect()->assertSessionHas('success');

        // Office flows (Feature 6) per round.
        $this->asAdmin();
        if ($cfg['office'] === 'lump') {
            $installment = (string) $loan->fresh()->installment_amount;
            $paidBefore = LoanSchedule::where('loan_account_id', $loan->id)->where('status', 'Paid')->count();
            $this->post('/loans/receive', [
                'loan_account_id' => $loan->id, 'amount' => (string) ((float) $installment * 15),
            ])->assertSessionHas('success');
            $paidNow = LoanSchedule::where('loan_account_id', $loan->id)->where('status', 'Paid')->count();
            $this->assertSame($paidBefore + 15, $paidNow, 'Lump sum settles exactly 15 more installments');
        }
        if ($cfg['office'] === 'savings') {
            $installment = (string) $loan->fresh()->installment_amount;
            $paidBefore = LoanSchedule::where('loan_account_id', $loan->id)->where('status', 'Paid')->count();
            $savBalBefore = (string) $savings->fresh()->current_balance;
            $amount = (string) ((float) $installment * 2);
            $this->post('/loans/receive', [
                'loan_account_id' => $loan->id, 'amount' => $amount,
                'transfer_from_deposit' => 1, 'deposit_account_id' => $savings->id,
            ])->assertSessionHas('success');
            $paidNow = LoanSchedule::where('loan_account_id', $loan->id)->where('status', 'Paid')->count();
            $this->assertSame($paidBefore + 2, $paidNow, 'Savings payment settles 2 installments');
            $this->assertSame(bcsub($savBalBefore, $amount, 2), (string) $savings->fresh()->current_balance, 'Savings debited by the office payment');
            // Insufficient balance rejects cleanly.
            $this->post('/loans/receive', [
                'loan_account_id' => $loan->id, 'amount' => '999999',
                'transfer_from_deposit' => 1, 'deposit_account_id' => $savings->id,
            ])->assertSessionHasErrors();
        }
        $this->assertTrue(app(PostingService::class)->trialBalance($this->branch->id)['balanced']);
    }

    public function test_round_one_weekly_samity_monthly_loan(): void
    {
        $samity = $this->makeSamity($this->branch, ['meeting_frequency' => 'weekly', 'samity_day' => 'Monday']);
        $this->savingsOnlyJourney($this->makeMember($samity, 'SavingsOnly'), '500', '300', '200');
        $this->ltsOnlyJourney($this->makeMember($samity, 'LtsOnly'), 100, 1);
        $this->loanJourney($samity, $this->makeMember($samity, 'Loan'), [
            'amount' => '12000', 'frequency' => 'M', 'installments' => 12,
            'customName' => 'Processing charge', 'customAmount' => '50.00',
            'savingsCollect' => '100', 'loanMemberLts' => null, 'office' => null,
        ]);
        $this->assertTrue(app(PostingService::class)->trialBalance($this->branch->id)['balanced']);
    }

    public function test_round_two_daily_samity_weekly_loan_with_lump_sum(): void
    {
        $samity = $this->makeSamity($this->branch, ['meeting_frequency' => 'daily']);
        $this->savingsOnlyJourney($this->makeMember($samity, 'SavingsOnly'), '1000', '500', '400');
        $this->ltsOnlyJourney($this->makeMember($samity, 'LtsOnly'), 200, 2);
        $this->loanJourney($samity, $this->makeMember($samity, 'Loan'), [
            'amount' => '20000', 'frequency' => 'W', 'installments' => 40,
            'customName' => 'Verification fee', 'customAmount' => '75.00',
            'savingsCollect' => '200', 'loanMemberLts' => [50, 1], 'office' => 'lump',
        ]);
        $this->assertTrue(app(PostingService::class)->trialBalance($this->branch->id)['balanced']);
    }

    public function test_round_three_monthly_samity_daily_loan_with_savings_payment(): void
    {
        // Branch creation through the real settings endpoint, with a
        // samity + savings-only member living in the new branch.
        $this->post('/settings/branches', [
            'code' => '199', 'name' => 'CRUD Test Branch', 'type' => 'Branch',
            'opening_date' => '2026-10-01', 'district' => 'Sirajganj', 'status' => 'Active',
        ])->assertSessionHas('success');
        $newBranch = Branch::where('code', '199')->firstOrFail();
        $branchSamity = $this->makeSamity($newBranch, ['meeting_frequency' => 'daily']);
        $this->savingsOnlyJourney($this->makeMember($branchSamity, 'SavingsOnly'), '700', '200', '100');

        $samity = $this->makeSamity($this->branch, ['meeting_frequency' => 'monthly', 'date_of_month' => 5]);
        $this->savingsOnlyJourney($this->makeMember($samity, 'SavingsOnly'), '2000', '1000', '500');
        $this->ltsOnlyJourney($this->makeMember($samity, 'LtsOnly'), 500, 5);
        $this->loanJourney($samity, $this->makeMember($samity, 'Loan'), [
            'amount' => '15000', 'frequency' => 'D', 'installments' => 50,
            'customName' => 'File charge', 'customAmount' => '30.00',
            'savingsCollect' => '150', 'loanMemberLts' => [100, 3], 'office' => 'savings',
        ]);
        $this->assertTrue(app(PostingService::class)->trialBalance($this->branch->id)['balanced']);
    }
}
