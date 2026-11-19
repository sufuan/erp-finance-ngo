<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DepositAccount;
use App\Models\DepositProduct;
use App\Models\LoanProduct;
use App\Models\Member;
use App\Models\Samity;
use App\Models\User;
use App\Services\LoanMoneyService;
use App\Services\LoanOpeningService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Client round 5 (2026-11-19) — Member Admission carries an account
 * choice: General Savings only, or General + Long-term (Both). The
 * general account opens WITH the membership at balance 0 (no cash at
 * admission), its installment_amount is the entered savings amount
 * (minimum ৳20); Both also opens the LTS account on the printed
 * slabs. A failed opening rolls the whole admission back, and the
 * edit form never creates accounts.
 */
class AdmissionAccountChoiceTest extends TestCase
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

    /** POST the real admission form; returns the member's mobile. */
    private function admit(array $extra = []): string
    {
        $this->memberSeq++;
        $mobile = '0171700000'.$this->memberSeq;
        $this->asAdmin();
        $this->post('/members', array_merge([
            'samity_id' => $this->samity->id, 'name' => 'Admission Choice '.$this->memberSeq,
            'gender' => 'Female', 'mobile' => $mobile, 'national_id' => '199850000'.$this->memberSeq,
            'share_amount' => 500, 'open_date' => '2026-10-21',
            'nominee_name' => 'Nominee', 'nominee_dob' => '1990-01-01', 'nominee_relation' => 'Sister',
        ], $extra))->assertRedirect('/members/admission');

        return $mobile;
    }

    private function memberByMobile(string $mobile): Member
    {
        return Member::withoutGlobalScopes()->where('mobile', $mobile)->firstOrFail();
    }

    private function accounts(Member $member, string $code): \Illuminate\Support\Collection
    {
        $product = DepositProduct::where('code', $code)->firstOrFail();

        return DepositAccount::where('member_id', $member->id)
            ->where('deposit_product_id', $product->id)->orderBy('id')->get();
    }

    public function test_general_only_opens_one_savings_account_at_the_minimum(): void
    {
        $member = $this->memberByMobile($this->admit(['account_type' => 'general', 'savings_amount' => 20]));

        $savings = $this->accounts($member, 'SAVINGS');
        $this->assertCount(1, $savings);
        $this->assertSame('20.00', $savings[0]->installment_amount);
        $this->assertSame('0.00', $savings[0]->current_balance);
        $this->assertTrue($savings[0]->is_active);
        $this->assertCount(0, $this->accounts($member, 'LTS'));
    }

    public function test_general_only_with_a_larger_savings_amount(): void
    {
        $member = $this->memberByMobile($this->admit(['account_type' => 'general', 'savings_amount' => 50]));

        $savings = $this->accounts($member, 'SAVINGS');
        $this->assertCount(1, $savings);
        $this->assertSame('50.00', $savings[0]->installment_amount);
        $this->assertSame('0.00', $savings[0]->current_balance);
    }

    public function test_savings_amount_below_20_is_rejected(): void
    {
        $this->memberSeq++;
        $mobile = '0171700000'.$this->memberSeq;
        $this->asAdmin();
        $this->post('/members', [
            'samity_id' => $this->samity->id, 'name' => 'Too Small Savings',
            'gender' => 'Female', 'mobile' => $mobile, 'national_id' => '199850000'.$this->memberSeq,
            'share_amount' => 500, 'open_date' => '2026-10-21',
            'account_type' => 'general', 'savings_amount' => 10,
        ])->assertSessionHasErrors('savings_amount');

        $this->assertDatabaseMissing('members', ['mobile' => $mobile]);
    }

    public function test_both_opens_general_and_lts_on_the_printed_terms(): void
    {
        $member = $this->memberByMobile($this->admit([
            'account_type' => 'both', 'savings_amount' => 20,
            'lts_amount' => 100, 'lts_term_years' => 3,
        ]));

        $this->assertCount(1, $this->accounts($member, 'SAVINGS'));
        $lts = $this->accounts($member, 'LTS');
        $this->assertCount(1, $lts);
        $this->assertSame('100.00', $lts[0]->installment_amount);
        // Payout-table products store NO flat rate — profit comes
        // from the printed table at closing.
        $this->assertSame('0.00', $lts[0]->profit_rate);
        $this->assertSame('2029-10-21', $lts[0]->maturity_date->toDateString());
        $this->assertSame('0.00', $lts[0]->current_balance);
    }

    public function test_an_lts_failure_rolls_back_the_whole_admission(): void
    {
        $this->memberSeq++;
        $mobile = '0171700000'.$this->memberSeq;
        $this->asAdmin();
        // 75 is not one of the printed LTS slab amounts (50–1000 step
        // 50), so the account opening fails — the member must not
        // survive without the accounts he chose.
        $this->post('/members', [
            'samity_id' => $this->samity->id, 'name' => 'Rollback Member',
            'gender' => 'Female', 'mobile' => $mobile, 'national_id' => '199850000'.$this->memberSeq,
            'share_amount' => 500, 'open_date' => '2026-10-21',
            'account_type' => 'both', 'savings_amount' => 20,
            'lts_amount' => 75, 'lts_term_years' => 3,
        ])->assertSessionHasErrors();

        $this->assertDatabaseMissing('members', ['mobile' => $mobile]);
    }

    public function test_member_edit_creates_no_accounts(): void
    {
        $member = $this->memberByMobile($this->admit(['account_type' => 'general', 'savings_amount' => 20]));
        $this->assertSame(1, DepositAccount::where('member_id', $member->id)->count());

        $this->asAdmin();
        $this->put('/members/'.$member->id, [
            'samity_id' => $this->samity->id, 'name' => $member->name,
            'gender' => 'Female', 'mobile' => $member->mobile, 'national_id' => $member->national_id,
            'share_amount' => 500, 'open_date' => '2026-10-21',
            // Even if an edit payload carries the choice fields, the
            // update path never opens accounts.
            'account_type' => 'both', 'savings_amount' => 30,
            'lts_amount' => 100, 'lts_term_years' => 2,
        ])->assertRedirect('/members/'.$member->id);

        $this->assertSame(1, DepositAccount::where('member_id', $member->id)->count());
        $this->assertCount(0, $this->accounts($member, 'LTS'));
    }

    public function test_loan_opening_reuses_the_admission_savings_account(): void
    {
        $member = $this->memberByMobile($this->admit(['account_type' => 'general', 'savings_amount' => 20]));
        $before = $this->accounts($member, 'SAVINGS');
        $this->assertCount(1, $before);

        $product = LoanProduct::where('code', '3001')->firstOrFail();
        $loan = app(LoanOpeningService::class)->open([
            'member_id' => $member->id, 'employee_id' => null, 'loan_proposal_id' => null,
            'loan_product_id' => $product->id, 'opening_date' => '2026-10-21',
            'loan_purpose' => 'Agriculture', 'loan_amount' => '12000', 'down_payment' => 0,
            'frequency' => 'M', 'no_of_installments' => 12, 'profit_rate' => null,
            'installment_from_date' => '2026-11-01', 'fees' => [], 'guarantors' => [],
        ], $this->admin);
        app(LoanMoneyService::class)->disburse($loan, $this->admin);

        // Still exactly ONE general savings account — the admission
        // one — and the 10% linked credit landed on it.
        $after = $this->accounts($member, 'SAVINGS');
        $this->assertCount(1, $after);
        $this->assertSame($before[0]->id, $after[0]->id);
        $this->assertSame('1200.00', $after[0]->fresh()->current_balance);
    }
}
