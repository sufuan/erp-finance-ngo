<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\LoanAccount;
use App\Models\LoanProduct;
use App\Models\LoanReceive;
use App\Models\Member;
use App\Models\Samity;
use App\Models\User;
use App\Services\LoanMoneyService;
use App\Services\LoanOpeningService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Regression (live defect, 2026-11-19): the member passbook 500'd for
 * any member WITH loan receives — passbookRows() eager-loads and reads
 * a `loanAccount` relation that LoanReceive never defined (only loan()
 * existed). CollectionReportService::loanCollectionDetails uses the
 * same relation name on its report route. LoanReceive now carries a
 * loanAccount() alias of loan(); both paths are exercised here through
 * the real routes.
 */
class PassbookLoanRelationTest extends TestCase
{
    use DatabaseTransactions;

    private Branch $branch;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::where('code', '102')->firstOrFail();
        $this->admin = User::factory()->create(['must_change_password' => false]);
        $this->admin->assignRole('super-admin');
        $this->actingAs($this->admin);
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

    /** @return array{0: Member, 1: LoanAccount} */
    private function memberWithPostedReceive(): array
    {
        $samity = Samity::withoutGlobalScopes()->where('name', 'Talgachi Samity')->firstOrFail();
        $this->post('/members', [
            'samity_id' => $samity->id, 'name' => 'Passbook Member',
            'gender' => 'Female', 'mobile' => '01719123456', 'national_id' => '1998700019901',
            'share_amount' => 500, 'open_date' => '2026-10-21',
            'nominee_name' => 'Nominee', 'nominee_dob' => '1990-01-01', 'nominee_relation' => 'Sister',
        ])->assertRedirect('/members/admission');
        $member = Member::withoutGlobalScopes()->where('mobile', '01719123456')->firstOrFail();

        $product = LoanProduct::where('code', '3001')->firstOrFail();
        $loan = app(LoanOpeningService::class)->open([
            'member_id' => $member->id, 'employee_id' => null, 'loan_proposal_id' => null,
            'loan_product_id' => $product->id, 'opening_date' => '2026-10-21',
            'loan_purpose' => 'Agriculture', 'loan_amount' => '10000', 'down_payment' => 0,
            'frequency' => 'D', 'no_of_installments' => 50, 'profit_rate' => null,
            'installment_from_date' => '2027-01-01', 'fees' => [], 'guarantors' => [],
        ], $this->admin);
        $loan = app(LoanMoneyService::class)->disburse($loan, $this->admin);

        $this->post('/loans/receive', [
            'loan_account_id' => $loan->id, 'amount' => (string) $loan->installment_amount,
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertSame(1, LoanReceive::where('loan_account_id', $loan->id)->whereNull('cancel_at')->count());

        return [$member, $loan];
    }

    public function test_passbook_page_renders_for_member_with_loan_receives(): void
    {
        [$member, $loan] = $this->memberWithPostedReceive();

        $props = $this->inertiaProps('/collection/passbook?member='.$member->member_no);

        $installmentRows = collect($props['rows'])
            ->filter(fn ($row) => str_contains($row['particulars'], 'Loan '.$loan->loan_number.' Installment'))
            ->values();
        $this->assertCount(1, $installmentRows, 'Passbook carries exactly the one posted installment row');
        $this->assertSame((string) $loan->installment_amount, $installmentRows[0]['credit']);
    }

    public function test_loan_collection_details_report_uses_the_same_relation(): void
    {
        [$member, $loan] = $this->memberWithPostedReceive();

        $props = $this->inertiaProps('/collection/reports?report=loan_collection_details&run=1');

        $mine = collect($props['rows'])
            ->filter(fn ($row) => $row[1] === $member->member_no)
            ->values();
        $this->assertCount(1, $mine, 'Report lists the posted receive for this member');
        $this->assertSame($loan->loan_number, $mine[0][3], 'Loan number resolved through the loanAccount relation');
    }
}
