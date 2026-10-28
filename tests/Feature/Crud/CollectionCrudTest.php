<?php

namespace Tests\Feature\Crud;

use App\Models\CollectionBatch;
use App\Models\DepositAccount;
use App\Models\DepositProduct;
use App\Models\LoanAccount;
use App\Models\LoanProduct;
use App\Models\Member;
use App\Models\OtherCollection;
use App\Models\PassbookIssue;
use App\Services\LoanMoneyService;
use App\Services\LoanOpeningService;
use Inertia\Testing\AssertableInertia;

/**
 * Collection module (Steps 21–23) through its REAL routes/forms:
 * sheet posting with a mixed deposit+loan+share sheet, the lists and
 * their cancels, member collection, other collection, passbook/cheque
 * issue, sheets/reports/balances and the member passbook — with the
 * money verified on the underlying deposit + loan accounts.
 */
class CollectionCrudTest extends CrudTestCase
{
    private int $memberSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // Work in the Talgachi branch (102), where the seeded samity lives.
        $this->post('/branch/switch', ['branch_id' => $this->branch('102')->id])->assertRedirect();
    }

    protected function makeMember(): Member
    {
        $this->memberSeq++;
        $mobile = '0171300000'.$this->memberSeq;
        $this->post('/members', [
            'samity_id' => $this->samity()->id, 'name' => 'Collection CRUD Member '.$this->memberSeq,
            'gender' => 'Female', 'mobile' => $mobile, 'national_id' => '199720000'.$this->memberSeq,
            'share_amount' => 500, 'open_date' => '2026-10-21',
            'nominee_name' => 'Nominee', 'nominee_dob' => '1990-01-01', 'nominee_relation' => 'Sister',
        ])->assertRedirect('/members/admission');

        return Member::where('mobile', $mobile)->firstOrFail();
    }

    protected function openDepositOverHttp(Member $member, string $productCode, ?string $installment = null): DepositAccount
    {
        $product = DepositProduct::where('code', $productCode)->firstOrFail();
        $this->post('/deposits', [
            'member_id' => $member->id, 'deposit_product_id' => $product->id,
            'phone' => $member->mobile, 'opening_date' => '2026-10-21',
            'installment_amount' => $installment,
        ])->assertRedirect('/deposits');

        return DepositAccount::where('member_id', $member->id)
            ->where('deposit_product_id', $product->id)->latest('id')->firstOrFail();
    }

    protected function disbursedLoan(Member $member): LoanAccount
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

    /** Read the Inertia page props from the rendered HTML shell. */
    protected function inertiaProps(string $url): array
    {
        $html = $this->get($url)->assertOk()->getContent();
        $found = preg_match('/<script data-page="app"[^>]*>(.*?)<\/script>/s', $html, $m);
        $this->assertSame(1, $found, 'Inertia shell carries the page JSON');

        return json_decode($m[1], true)['props'] ?? [];
    }

    public function test_all_collection_pages_render(): void
    {
        $pages = [
            '/collection/member' => 'Collections/MemberCollection',
            '/collection/sheet' => 'Collections/SheetPosting',
            '/collection/simple-sheet' => 'Collections/SimpleSheetPosting',
            '/collection/member-list' => 'Collections/MemberCollectionList',
            '/collection/samity-list' => 'Collections/SamityCollectionList',
            '/collection/other' => 'Collections/OtherCollection',
            '/collection/sheets' => 'Collections/Sheets',
            '/collection/reports' => 'Collections/Reports',
            '/collection/balances' => 'Collections/Reports',
            '/collection/passbook-issue' => 'Collections/PassbookIssue',
            '/collection/cheque-issue' => 'Collections/ChequeBookIssue',
            '/collection/passbook' => 'Collections/MemberPassbook',
        ];
        foreach ($pages as $url => $component) {
            $this->get($url)->assertOk()
                ->assertInertia(fn (AssertableInertia $p) => $p->component($component));
        }
        $this->get('/hub/collection')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Hub/Show')->where('module', 'collection'));
    }

    public function test_sheet_posting_full_cycle_over_http(): void
    {
        $member = $this->makeMember();
        $savings = $this->openDepositOverHttp($member, 'SAVINGS');
        $loan = $this->disbursedLoan($member);
        $samity = $this->samity();

        // The sheet grid renders the member's accounts with receivables.
        $outstandingBefore = app(LoanMoneyService::class)->outstanding($loan)['total'];
        $gridJson = $this->inertiaProps('/collection/sheet?samity_id='.$samity->id)['grid'] ?? [];
        $gridRow = collect($gridJson)->firstWhere('member_id', $member->id);
        $this->assertNotNull($gridRow);
        $loanCell = collect($gridRow['accounts'])->firstWhere('account_type', 'loan');
        $this->assertSame('1083.10', $loanCell['receivable']);

        // Post a mixed sheet: deposit 200 + loan installment + share 100.
        $this->post('/collection/sheet', [
            'samity_id' => $samity->id,
            'lines' => [
                ['member_id' => $member->id, 'account_type' => 'deposit', 'account_id' => $savings->id, 'amount' => '200'],
                ['member_id' => $member->id, 'account_type' => 'loan', 'account_id' => $loan->id, 'amount' => '1083.10'],
                ['member_id' => $member->id, 'account_type' => 'share', 'account_id' => $member->id, 'amount' => '100'],
            ],
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame('200.00', (string) $savings->fresh()->current_balance);
        $this->assertSame('600.00', (string) $member->fresh()->share_amount);
        $batch = CollectionBatch::where('samity_id', $samity->id)->latest('id')->firstOrFail();
        $this->assertSame('1383.10', (string) $batch->total_receive);
        $outstanding = app(LoanMoneyService::class)->outstanding($loan->fresh())['total'];
        $this->assertSame(
            (string) (\Brick\Math\BigDecimal::of($outstandingBefore)->minus('1083.10')->toScale(2)),
            $outstanding
        );

        // Lists show the posting.
        $listRows = $this->inertiaProps('/collection/samity-list')['rows'] ?? [];
        $listRow = collect($listRows)->firstWhere('id', $batch->id);
        $this->assertSame('1383.10', $listRow['total_receive']);
        $memberRows = $this->inertiaProps('/collection/member-list?member='.$member->member_no)['rows'] ?? [];
        $this->assertCount(1, $memberRows);
        $this->assertSame('1383.10', $memberRows[0]['total_receive']);

        // Cancel the whole batch from the samity list → money reverses.
        $this->post('/collection/batches/'.$batch->id.'/cancel')->assertRedirect()->assertSessionHas('success');
        $this->assertSame('0.00', (string) $savings->fresh()->current_balance);
        // Share returns to the member's admission-backed 500.00.
        $this->assertSame('500.00', (string) $member->fresh()->share_amount);
        $this->assertSame($outstandingBefore, app(LoanMoneyService::class)->outstanding($loan->fresh())['total']);
        $this->assertSame('Cancelled', $batch->fresh()->status);
    }

    public function test_member_collection_other_and_instruments_over_http(): void
    {
        $member = $this->makeMember();
        $savings = $this->openDepositOverHttp($member, 'SAVINGS');

        $this->get('/collection/member?member='.$member->member_no)->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Collections/MemberCollection')->has('grid', 1));
        $this->post('/collection/member', [
            'member_id' => $member->id,
            'lines' => [
                ['member_id' => $member->id, 'account_type' => 'deposit', 'account_id' => $savings->id, 'amount' => '300'],
            ],
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertSame('300.00', (string) $savings->fresh()->current_balance);

        // Member-list cancel reverses just that member's lines.
        $batch = CollectionBatch::where('member_id', $member->id)->latest('id')->firstOrFail();
        $this->post('/collection/batches/'.$batch->id.'/cancel-member', ['member_id' => $member->id])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame('0.00', (string) $savings->fresh()->current_balance);

        // Other collection receive + cancel.
        $this->post('/collection/other', [
            'collection_type' => 'Admission Fee', 'collection_account' => 'Cash Counter',
            'amount' => '150', 'member' => $member->member_no, 'document_number' => 'RC-1', 'comments' => 'Fee',
        ])->assertRedirect()->assertSessionHas('success');
        $other = OtherCollection::latest('id')->firstOrFail();
        $this->assertSame('150.00', (string) $other->amount);
        $this->post('/collection/other/'.$other->id.'/cancel')->assertRedirect();
        $this->assertNotNull($other->fresh()->cancel_at);

        // Passbook + cheque book issue and cancel.
        $this->post('/collection/passbook-issue', [
            'member_id' => $member->id, 'book_number' => 'PB-200', 'fee_amount' => '25', 'document_number' => null,
        ])->assertRedirect()->assertSessionHas('success');
        $issue = PassbookIssue::latest('id')->firstOrFail();
        $this->post('/collection/passbook-issue/'.$issue->id.'/cancel')->assertRedirect();
        $this->assertNotNull($issue->fresh()->cancel_at);

        $this->post('/collection/cheque-issue', [
            'member_id' => $member->id, 'cheque_prefix' => 'CB', 'start_leaf' => '101', 'end_leaf' => '125',
            'fee_amount' => '0', 'document_number' => null,
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseHas('cheque_book_issues', ['member_id' => $member->id, 'start_leaf' => '101']);
    }

    /**
     * Regression (2026-10-28): the Passbook Issue page first mounts with
     * no member; the search round-trip supplies the member and the next
     * book number as props, and the (fixed) form posts exactly those.
     * Driving the endpoints the same way must issue the book — before
     * the form fix, the post carried an empty member_id and validation
     * rejected it with "The member id field is required."
     */
    public function test_passbook_issue_via_lookup_then_submit(): void
    {
        $member = $this->makeMember();

        // The lookup the search button performs.
        $props = $this->inertiaProps('/collection/passbook-issue?member='.$member->member_no);
        $this->assertSame($member->id, $props['member']['id'] ?? null);
        $bookNumber = (string) ($props['nextBookNumber'] ?? '');
        $this->assertNotSame('', $bookNumber);

        // The payload the fixed form submits.
        $this->post('/collection/passbook-issue', [
            'member_id' => $props['member']['id'], 'book_number' => $bookNumber,
            'fee_amount' => '25', 'document_number' => 'PB-DOC-1',
        ])->assertRedirect()->assertSessionHas('success');

        $issue = PassbookIssue::where('member_id', $member->id)->latest('id')->firstOrFail();
        $this->assertSame($bookNumber, $issue->book_number);
        $this->assertSame('25.00', (string) $issue->fee_amount);
        $this->assertNotNull($issue->voucher_id, 'a positive fee still posts its voucher');

        // A second lookup offers the next number; an untouched (empty) fee
        // records the issue with no voucher, exactly as before.
        $props = $this->inertiaProps('/collection/passbook-issue?member='.$member->member_no);
        $this->post('/collection/passbook-issue', [
            'member_id' => $member->id, 'book_number' => $props['nextBookNumber'],
            'fee_amount' => '', 'document_number' => null,
        ])->assertRedirect()->assertSessionHas('success');
        $free = PassbookIssue::where('member_id', $member->id)->latest('id')->firstOrFail();
        $this->assertSame((string) $props['nextBookNumber'], $free->book_number);
        $this->assertNull($free->voucher_id);

        // The guard the bug tripped: an empty member_id is still rejected.
        $this->post('/collection/passbook-issue', [
            'member_id' => '', 'book_number' => 'X', 'fee_amount' => '', 'document_number' => null,
        ])->assertSessionHasErrors('member_id');
    }

    public function test_reports_sheets_and_passbook_over_http(): void
    {
        $member = $this->makeMember();
        $savings = $this->openDepositOverHttp($member, 'SAVINGS');
        $this->post('/collection/member', [
            'member_id' => $member->id,
            'lines' => [
                ['member_id' => $member->id, 'account_type' => 'deposit', 'account_id' => $savings->id, 'amount' => '275'],
            ],
        ])->assertRedirect();

        foreach (['collection_sheet', 'deposit_collection_sheet', 'loan_collection_sheet'] as $sheet) {
            $this->get('/collection/sheets?report='.$sheet.'&run=1&samity_id='.$this->samity()->id)
                ->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Collections/Sheets')->where('ran', true)->etc());
        }
        foreach (['collection_details', 'collection_summary', 'dcr_report', 'pomis_1', 'pomis_2', 'pomis_3', 'pomis_4', 'mis_topsheet'] as $report) {
            $this->get('/collection/reports?report='.$report.'&run=1&from_date=2020-01-01&to_date=2030-01-01')
                ->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Collections/Reports')->where('ran', true)->etc());
            $pdf = $this->get('/collection/reports/'.$report.'/pdf?from_date=2020-01-01&to_date=2030-01-01');
            $pdf->assertOk();
            $this->assertStringStartsWith('%PDF', substr($pdf->getContent(), 0, 4));
        }
        foreach (['member_balance', 'samity_balance', 'officer_balance'] as $report) {
            $this->get('/collection/balances?report='.$report.'&run=1')
                ->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('ran', true)->etc());
        }

        // Member passbook shows the collection and prints.
        $passbookRows = $this->inertiaProps('/collection/passbook?member='.$member->member_no)['rows'] ?? [];
        $this->assertSame('275.00', collect($passbookRows)->firstWhere('credit', '275.00')['credit'] ?? null);
        $pdf = $this->get('/collection/passbook/pdf?member='.$member->member_no);
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', substr($pdf->getContent(), 0, 4));
    }
}
