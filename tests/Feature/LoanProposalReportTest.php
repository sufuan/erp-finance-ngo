<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\LoanProduct;
use App\Models\LoanProposal;
use App\Models\Member;
use App\Models\User;
use App\Services\LoanProposalService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Per-proposal PDF report (owner order 2026-11-14): GET
 * /loans/proposals/{proposal}/report streams the proposal printout inline.
 * Guarded by loans.print (route) and by branch scope (controller) — a
 * printer in another branch must never see this branch's paperwork.
 */
class LoanProposalReportTest extends TestCase
{
    use DatabaseTransactions;

    private LoanProposal $proposal;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::factory()->create(['must_change_password' => false]);
        $admin->assignRole('super-admin');
        $member = Member::query()->firstOrFail();
        $product = LoanProduct::where('code', '3001')->firstOrFail();
        $this->proposal = app(LoanProposalService::class)->submit($member, [
            'loan_product_id' => $product->id, 'proposal_date' => '2026-10-21',
            'loan_purpose' => 'Agriculture', 'loan_amount' => '12000',
            'fdr_account_id' => null, 'frequency' => 'M', 'no_of_installments' => 12,
            'profit_rate' => null, 'installment_from_date' => '2026-11-01',
            'guarantors' => [['name' => 'Karim', 'relation' => 'Brother', 'phone' => '01710000000', 'address' => 'Talgachi', 'national_id' => '1234567890']],
        ], $admin);
    }

    private function actAs(User $user): void
    {
        $this->actingAs($user);
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);
    }

    public function test_user_with_print_permission_gets_inline_pdf(): void
    {
        $admin = User::factory()->create(['must_change_password' => false]);
        $admin->assignRole('super-admin');
        $this->actAs($admin);

        $response = $this->get(route('loans.proposals.report', $this->proposal));
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_user_without_print_permission_is_forbidden(): void
    {
        $viewer = User::factory()->create(['must_change_password' => false]);
        $role = Role::create(['name' => 'proposal-viewer-only']);
        $role->givePermissionTo('loans.view');
        $viewer->assignRole($role);
        $this->actAs($viewer);

        $this->get(route('loans.proposals.report', $this->proposal))->assertForbidden();
    }

    public function test_printer_from_another_branch_is_forbidden(): void
    {
        $otherBranch = Branch::where('id', '!=', $this->proposal->branch_id)->firstOrFail();
        $printer = User::factory()->create(['must_change_password' => false]);
        $role = Role::create(['name' => 'proposal-printer']);
        $role->givePermissionTo(['loans.view', 'loans.print']);
        $printer->assignRole($role);
        $printer->branches()->attach($otherBranch->id);
        $this->actAs($printer);

        $this->get(route('loans.proposals.report', $this->proposal))->assertForbidden();
    }
}
