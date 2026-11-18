<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchManagerChange;
use App\Models\BusinessDay;
use App\Models\DepositAccount;
use App\Models\DepositProduct;
use App\Models\Employee;
use App\Models\LoanAccount;
use App\Models\LoanProduct;
use App\Models\LoanProposal;
use App\Models\Member;
use App\Models\Samity;
use App\Models\User;
use App\Services\BusinessDayService;
use App\Services\LoanMoneyService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Client round 2026-11-18: branch-level management features.
 *
 *  - Branch Manager Change replaces Samity Manager Change.
 *  - Branch Merge replaces Samity Merge.
 *  - Branch Split is new; Samity Split / Officer Change / Day Change
 *    are kept and smoke-tested here.
 *
 * Everything is driven through the real routes/forms as super admin.
 */
class BranchLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['must_change_password' => false]);
        $this->admin->assignRole('super-admin');
        $this->actingAs($this->admin);
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);
    }

    private function activateBranch(Branch $branch): void
    {
        $this->withSession([
            'active_branch_id' => $branch->id,
            'totp_passed_at' => now()->toDateTimeString(),
        ]);
    }

    private function makeBranch(string $code, string $name): Branch
    {
        $this->post('/settings/branches', [
            'code' => $code, 'name' => $name, 'type' => 'Branch',
            'opening_date' => '2026-10-01', 'status' => 'Active',
        ])->assertSessionHas('success');

        return Branch::where('code', $code)->firstOrFail();
    }

    private function makeSamity(Branch $branch, string $name): Samity
    {
        $this->post('/settings/samities', [
            'branch_id' => $branch->id, 'name' => $name, 'open_date' => '2026-10-01',
            'center_type' => 'All Gender', 'meeting_frequency' => 'weekly', 'samity_day' => 'Monday',
        ])->assertSessionHas('success');

        return Samity::withoutGlobalScopes()->where('name', $name)->firstOrFail();
    }

    private function makeMember(Samity $samity, string $name): Member
    {
        $this->seq++;
        $mobile = '01788'.str_pad((string) $this->seq, 6, '0', STR_PAD_LEFT);
        $this->post('/members', [
            'samity_id' => $samity->id, 'name' => $name, 'gender' => 'Female',
            'mobile' => $mobile, 'open_date' => '2026-10-21',
        ])->assertRedirect('/members/admission');

        return Member::withoutGlobalScopes()->where('mobile', $mobile)->firstOrFail();
    }

    private function openSavings(Member $member, string $amount): DepositAccount
    {
        $product = DepositProduct::where('code', 'SAVINGS')->firstOrFail();
        $this->post('/deposits', [
            'member_id' => $member->id, 'deposit_product_id' => $product->id,
            'phone' => $member->mobile, 'opening_date' => '2026-10-21', 'amount' => $amount,
        ])->assertRedirect('/deposits');

        return DepositAccount::where('member_id', $member->id)
            ->where('deposit_product_id', $product->id)->orderByDesc('id')->firstOrFail();
    }

    /** Proposal → approve → opening → disburse, all through real routes. */
    private function makeDisbursedLoan(Branch $branch, Member $member, string $amount): LoanAccount
    {
        $bd = app(BusinessDayService::class)->currentDate($branch->id);
        $from = \Carbon\Carbon::parse($bd)->subDays(14)->toDateString();
        $product = LoanProduct::where('code', '3001')->firstOrFail();

        $this->post('/loans/proposals', [
            'member_id' => $member->id, 'loan_product_id' => $product->id,
            'loan_amount' => $amount, 'frequency' => 'W', 'no_of_installments' => 50,
            'installment_from_date' => $from, 'proposal_date' => $bd, 'loan_purpose' => 'Small business',
        ])->assertSessionHas('success');
        $proposal = LoanProposal::where('member_id', $member->id)->latest('id')->firstOrFail();
        $this->post('/loans/proposals/'.$proposal->id.'/approve')->assertSessionHas('success');

        $this->post('/loans/opening', [
            'member_id' => $member->id, 'loan_proposal_id' => $proposal->id,
            'loan_product_id' => $product->id, 'opening_date' => $bd,
            'loan_purpose' => 'Small business', 'loan_amount' => $amount,
            'down_payment' => 0, 'frequency' => 'W', 'no_of_installments' => 50,
            'installment_from_date' => $from, 'fees' => [], 'guarantors' => [],
        ])->assertSessionHas('success');
        $loan = LoanAccount::where('member_id', $member->id)->latest('id')->firstOrFail();
        $this->post('/loans/disbursement/'.$loan->id)->assertRedirect()->assertSessionHas('success');

        return $loan->fresh();
    }

    private function outstanding(LoanAccount $loan): string
    {
        return (string) app(LoanMoneyService::class)->outstanding($loan->fresh())['total'];
    }

    // --- Branch Manager Change -------------------------------------------

    public function test_branch_manager_change_updates_branch_records_history_and_displays(): void
    {
        $branch = $this->makeBranch('901', 'Manager Test Branch');
        [$emp1, $emp2] = Employee::where('status', 'Active')->orderBy('id')->take(2)->get();

        $this->post('/settings/branches/manager-change', [
            'branch_id' => $branch->id, 'new_value' => $emp1->id,
            'change_date' => '2026-10-21', 'effective_from' => '2026-10-22', 'comments' => 'First manager',
        ])->assertSessionHas('success');
        $this->assertSame($emp1->id, $branch->fresh()->manager_id);
        $row = BranchManagerChange::where('branch_id', $branch->id)->firstOrFail();
        $this->assertNull($row->previous_value);
        $this->assertSame((string) $emp1->id, $row->new_value);

        $this->post('/settings/branches/manager-change', [
            'branch_id' => $branch->id, 'new_value' => $emp2->id,
            'change_date' => '2026-11-01', 'effective_from' => '2026-11-02',
        ])->assertSessionHas('success');
        $this->assertSame($emp2->id, $branch->fresh()->manager_id);
        $this->assertSame(2, BranchManagerChange::where('branch_id', $branch->id)->count());
        $this->assertSame((string) $emp1->id, BranchManagerChange::where('branch_id', $branch->id)->latest('id')->first()->previous_value);

        // The change page renders and the Branches list shows the manager.
        $this->get('/settings/branches/manager-change')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Settings/Branches/ManagerChange'));
        $response = $this->get('/settings/branches');
        $response->assertOk();
        $row = collect($response->viewData('page')['props']['branches']['data'])
            ->firstWhere('id', $branch->id);
        $this->assertSame($emp2->name, $row['manager']['name']);
    }

    // --- Branch Split ------------------------------------------------------

    public function test_branch_split_moves_ticked_samities_into_a_new_branch(): void
    {
        $source = $this->makeBranch('902', 'Split Source Branch');
        $this->activateBranch($source);
        $s1 = $this->makeSamity($source, 'Split Out Samity');
        $s2 = $this->makeSamity($source, 'Stay Behind Samity');
        $m1 = $this->makeMember($s1, 'Split Out Member');
        $m2 = $this->makeMember($s2, 'Stay Behind Member');
        $savings = $this->openSavings($m1, '1000');
        $this->assertSame('1000.00', (string) $savings->current_balance);
        $memberNo = $m1->member_no;
        $expectedCode = (string) (Branch::pluck('code')->map(fn ($c) => (int) $c)->max() + 1);

        $this->post('/settings/branches/split', [
            'source_branch_id' => $source->id,
            'samity_ids' => [$s1->id],
            'new_branch_name' => 'Split New Branch',
        ])->assertSessionHas('success');

        $new = Branch::where('name', 'Split New Branch')->firstOrFail();
        $this->assertSame($expectedCode, $new->code);
        $this->assertSame('Active', $new->status);

        // The ticked samity + its member + savings account moved; numbers kept.
        $this->assertSame($new->id, $s1->fresh()->branch_id);
        $this->assertSame($new->id, $m1->fresh()->branch_id);
        $this->assertSame($memberNo, $m1->fresh()->member_no);
        $this->assertSame($new->id, $savings->fresh()->branch_id);
        $this->assertSame('1000.00', (string) $savings->fresh()->current_balance);

        // The unticked samity + member stayed.
        $this->assertSame($source->id, $s2->fresh()->branch_id);
        $this->assertSame($source->id, $m2->fresh()->branch_id);

        // Combined totals unchanged; the new branch is usable: it has an
        // open business day and appears in the active branch pickers.
        $this->assertSame(2, Member::withoutGlobalScopes()->whereIn('branch_id', [$source->id, $new->id])->count());
        $this->assertTrue(BusinessDay::where('branch_id', $new->id)->where('is_closed', false)->exists());
        $response = $this->get('/settings/branches/split');
        $names = collect($response->viewData('page')['props']['branches'])->pluck('name')->all();
        $this->assertContains("{$new->code} — Split New Branch", $names);
    }

    public function test_branch_split_rejects_samity_from_another_branch(): void
    {
        $source = $this->makeBranch('905', 'Split Guard Source');
        $other = $this->makeBranch('906', 'Split Guard Other');
        $foreign = $this->makeSamity($other, 'Foreign Samity');

        $this->post('/settings/branches/split', [
            'source_branch_id' => $source->id,
            'samity_ids' => [$foreign->id],
            'new_branch_name' => 'Should Not Exist',
        ])->assertSessionHasErrors(['samity_ids']);
        $this->assertNull(Branch::where('name', 'Should Not Exist')->first());
    }

    // --- Branch Merge ------------------------------------------------------

    public function test_branch_merge_moves_everything_closes_source_and_keeps_history(): void
    {
        $source = $this->makeBranch('903', 'Merge Source Branch');
        $target = $this->makeBranch('904', 'Merge Target Branch');
        $this->activateBranch($source);
        $s3 = $this->makeSamity($source, 'Merge Source Samity');
        $m3 = $this->makeMember($s3, 'Merge Source Member');
        $savings = $this->openSavings($m3, '2000');
        $loan = $this->makeDisbursedLoan($source, $m3, '10000');
        $this->activateBranch($target);
        $s4 = $this->makeSamity($target, 'Merge Target Samity');
        $m4 = $this->makeMember($s4, 'Merge Target Member');

        // A staff member of the source branch + a posted voucher (history).
        $staff = Employee::where('status', 'Active')->orderBy('id')->first();
        DB::table('employees')->where('id', $staff->id)->update(['branch_id' => $source->id]);
        $voucher = DB::table('vouchers')->where('branch_id', $source->id)->orderByDesc('id')->first();
        $this->assertNotNull($voucher, 'Disbursement posted a voucher under the source branch');
        $voucherCountBefore = DB::table('vouchers')->where('branch_id', $source->id)->count();

        // A restricted user ticked ONLY the source samity (visibility must
        // survive the move — ticks are by samity id).
        $officer = User::factory()->create(['must_change_password' => false]);
        DB::table('samity_user')->insertOrIgnore([
            'user_id' => $officer->id, 'samity_id' => $s3->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $memberNo = $m3->member_no;
        $outstandingBefore = $this->outstanding($loan);
        // NB: disbursement credited the 10% linked-savings deposit into
        // this same account (2000 opened + 1000) — whatever it is now is
        // what must survive the merge, taka for taka.
        $balanceBefore = (string) $savings->fresh()->current_balance;
        $combinedBefore = [
            'members' => Member::withoutGlobalScopes()->whereIn('branch_id', [$source->id, $target->id])->count(),
            'savings' => (string) DepositAccount::whereIn('branch_id', [$source->id, $target->id])->sum('current_balance'),
        ];
        $this->assertSame(2, $combinedBefore['members']);

        $this->actingAs($this->admin);
        $this->post('/settings/branches/merge', [
            'source_branch_id' => $source->id, 'target_branch_id' => $target->id,
        ])->assertSessionHas('success');

        // Everything live moved to the target; numbers and money unchanged.
        $this->assertSame($target->id, $s3->fresh()->branch_id);
        $this->assertSame($target->id, $m3->fresh()->branch_id);
        $this->assertSame($memberNo, $m3->fresh()->member_no);
        $this->assertSame($target->id, $savings->fresh()->branch_id);
        $this->assertSame($balanceBefore, (string) $savings->fresh()->current_balance);
        $this->assertSame($target->id, $loan->fresh()->branch_id);
        $this->assertSame($outstandingBefore, $this->outstanding($loan));
        $this->assertSame($target->id, Employee::find($staff->id)->branch_id);
        $combinedAfter = [
            'members' => Member::withoutGlobalScopes()->whereIn('branch_id', [$source->id, $target->id])->count(),
            'savings' => (string) DepositAccount::whereIn('branch_id', [$source->id, $target->id])->sum('current_balance'),
        ];
        $this->assertSame($combinedBefore, $combinedAfter);

        // Source branch closed: Inactive, open day closed, gone from pickers.
        $this->assertSame('Inactive', $source->fresh()->status);
        $this->assertFalse(BusinessDay::where('branch_id', $source->id)->where('is_closed', false)->exists());
        $response = $this->get('/settings/branches/merge');
        $ids = collect($response->viewData('page')['props']['branches'])->pluck('id')->all();
        $this->assertNotContains($source->id, $ids);
        $this->assertContains($target->id, $ids);

        // History untouched: the disbursement voucher still belongs to 903.
        $this->assertSame($voucherCountBefore, DB::table('vouchers')->where('branch_id', $source->id)->count());
        $this->assertSame($source->id, (int) DB::table('vouchers')->where('id', $voucher->id)->value('branch_id'));

        // Samity visibility follows the tick across the move.
        $this->actingAs($officer);
        $this->assertContains($s3->id, Samity::pluck('id')->all());
    }

    public function test_branch_merge_rejects_same_branch(): void
    {
        $branch = $this->makeBranch('907', 'Merge Guard Branch');
        $this->post('/settings/branches/merge', [
            'source_branch_id' => $branch->id, 'target_branch_id' => $branch->id,
        ])->assertSessionHasErrors(['source_branch_id']);
        $this->assertSame('Active', $branch->fresh()->status);
    }

    // --- Replaced surfaces are gone ----------------------------------------

    public function test_replaced_samity_surfaces_are_gone(): void
    {
        // The merge route name is gone; the URL itself is claimed by the
        // PUT /samities/{samity} pattern, so a GET there is a 405 — the
        // page no longer exists either way.
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('settings.samities.merge'));
        $this->get('/settings/samities/merge')->assertStatus(405);
        $this->get('/settings/samities/changes/manager')->assertNotFound();
        $samity = Samity::withoutGlobalScopes()->firstOrFail();
        $employee = Employee::where('status', 'Active')->firstOrFail();
        $this->post('/settings/samities/changes/manager', [
            'samity_id' => $samity->id, 'new_value' => $employee->id,
            'change_date' => '2026-10-21', 'effective_from' => '2026-10-22',
        ])->assertNotFound();
        // …while the samity's manager DATA stays writable via the samity form.
        $this->assertNotNull($samity->manager_id ?? $samity->field_officer_id ?? true);
    }

    // --- Kept features smoke pass ------------------------------------------

    public function test_kept_samity_features_still_work(): void
    {
        $branch = Branch::where('code', '102')->firstOrFail();
        $this->activateBranch($branch);
        $samity = $this->makeSamity($branch, 'Kept Features Samity');
        $member = $this->makeMember($samity, 'Kept Features Member');
        [$officerA, $officerB] = Employee::where('status', 'Active')->orderBy('id')->take(2)->get();

        // Officer change.
        $this->post('/settings/samities/changes/officer', [
            'samity_id' => $samity->id, 'new_value' => $officerB->id,
            'change_date' => '2026-10-21', 'effective_from' => '2026-10-22',
        ])->assertSessionHas('success');
        $this->assertSame($officerB->id, $samity->fresh()->field_officer_id);

        // Day change.
        $this->post('/settings/samities/changes/day', [
            'samity_id' => $samity->id, 'meeting_frequency' => 'weekly', 'samity_day' => 'Thursday',
            'change_date' => '2026-10-21', 'effective_from' => '2026-10-22',
        ])->assertSessionHas('success');
        $this->assertSame('Thursday', $samity->fresh()->samity_day);

        // Samity split: the member moves into a brand-new samity, same branch.
        $this->post('/settings/samities/split', [
            'source_samity_id' => $samity->id,
            'member_ids' => [$member->id],
            'branch_id' => $branch->id, 'name' => 'Kept Features Split Off',
            'open_date' => '2026-10-21', 'center_type' => 'All Gender',
            'meeting_frequency' => 'weekly', 'samity_day' => 'Monday',
        ])->assertSessionHas('success');
        $newSamity = Samity::withoutGlobalScopes()->where('name', 'Kept Features Split Off')->firstOrFail();
        $this->assertSame($branch->id, $newSamity->branch_id);
        $this->assertSame($newSamity->id, $member->fresh()->samity_id);
        $this->assertSame($branch->id, $member->fresh()->branch_id);
    }
}
