<?php

namespace Tests\Feature\Crud;

use App\Models\DuePenaltySetup;
use App\Models\EmiOptionTemplate;
use App\Models\LoanAccount;
use App\Models\LoanPenalty;
use App\Models\LoanProduct;
use App\Models\LoanProposal;
use App\Models\LoanReceive;
use App\Models\LoanWaiver;
use App\Models\Member;
use App\Models\Writeoff;

/**
 * Loans: product + EMI-template CRUD, the proposal → approve → open →
 * disburse → receive → cancel-receive pipeline, due-penalty setup, the
 * four adjustments (reschedule / waiver / penalty / write-off), and the
 * LLP + loan report pages.
 */
class LoanCrudTest extends CrudTestCase
{
    private int $seq = 0;

    private function makeMember(): Member
    {
        $this->seq++;
        $mobile = '0171300000'.$this->seq;
        $this->post('/members', [
            'samity_id' => $this->samity()->id, 'name' => 'Loan CRUD Member '.$this->seq,
            'gender' => 'Male', 'mobile' => $mobile, 'national_id' => '199630000'.$this->seq,
            'share_amount' => 500, 'open_date' => '2026-10-21',
            'nominee_name' => 'Nominee', 'nominee_dob' => '1990-01-01', 'nominee_relation' => 'Brother',
        ])->assertRedirect('/members/admission');

        return Member::where('mobile', $mobile)->firstOrFail();
    }

    private function product(string $code = '3001'): LoanProduct
    {
        return LoanProduct::where('code', $code)->firstOrFail();
    }

    /** Open + disburse a loan entirely through the HTTP routes. */
    private function openAndDisburse(Member $member, array $extra = []): LoanAccount
    {
        $this->post('/loans/opening', array_merge([
            'member_id' => $member->id, 'loan_product_id' => $this->product()->id,
            'opening_date' => '2026-10-21', 'loan_purpose' => 'CRUD loan',
            'loan_amount' => 12000, 'down_payment' => 0,
            'frequency' => 'M', 'no_of_installments' => 12,
            'installment_from_date' => '2026-11-01', 'fees' => [], 'guarantors' => [],
        ], $extra))->assertRedirect()->assertSessionHas('success');

        $loan = LoanAccount::where('member_id', $member->id)->latest('id')->firstOrFail();
        $this->post("/loans/disbursement/{$loan->id}")
            ->assertRedirect()->assertSessionHas('success');

        return $loan->fresh();
    }

    public function test_loan_products_and_emi_templates_crud(): void
    {
        $payload = [
            'code' => '3888', 'name_en' => 'CRUD Loan', 'name_bn' => 'ক্রুড ঋণ',
            'profit_rate' => 15, 'rate_method' => 'declining',
            'min_amount' => 1000, 'max_amount' => 100000, 'min_term' => 1, 'max_term' => 24,
            'allowed_frequencies' => ['M', 'W'], 'grace_days' => 3, 'penal_rate' => 2,
            'gl_mapping_prefix' => 'loan.crud', 'min_cycle' => 1, 'max_cycle' => 5,
            'min_savings_balance' => 0, 'max_concurrent_loans' => 1, 'min_member_months' => 0,
            'fees' => 'form,document', 'is_active' => true,
        ];
        $this->post('/loans/products', $payload)->assertRedirect()->assertSessionHas('success');
        $product = $this->product('3888');
        $this->assertSame(['form', 'document'], $product->fee_config['fees']);

        $this->get('/loans/products')->assertOk();

        $payload['name_en'] = 'CRUD Loan Renamed';
        $payload['fees'] = 'form';
        $this->put("/loans/products/{$product->id}", $payload)
            ->assertRedirect()->assertSessionHas('success');
        $product->refresh();
        $this->assertSame('CRUD Loan Renamed', $product->name_en);
        $this->assertSame(['form'], $product->fee_config['fees']);

        $this->delete("/loans/products/{$product->id}")
            ->assertRedirect()->assertSessionHas('success');
        $this->assertNull(LoanProduct::find($product->id));

        // EMI / installment settings templates.
        $base = $this->product();
        $this->post('/loans/installment-settings', [
            'loan_product_id' => $base->id, 'frequency' => 'M', 'no_of_installments' => 10,
            'profit_rate' => 12, 'comments' => 'CRUD template', 'is_active' => true,
        ])->assertRedirect()->assertSessionHas('success');
        $template = EmiOptionTemplate::where('loan_product_id', $base->id)
            ->where('no_of_installments', 10)->latest('id')->firstOrFail();

        $this->get('/loans/installment-settings')->assertOk();
        $this->put("/loans/installment-settings/{$template->id}", [
            'frequency' => 'M', 'no_of_installments' => 8, 'profit_rate' => 12,
            'comments' => 'CRUD template edited', 'is_active' => true,
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertSame(8, $template->fresh()->no_of_installments);

        $this->delete("/loans/installment-settings/{$template->id}")
            ->assertRedirect()->assertSessionHas('success');
        $this->assertNull(EmiOptionTemplate::find($template->id));
    }

    public function test_proposal_approve_open_disburse_receive_and_cancel(): void
    {
        $member = $this->makeMember();
        $product = $this->product();

        // Schedule preview (the form's AJAX call).
        $this->postJson('/loans/proposals/preview', [
            'loan_product_id' => $product->id, 'loan_amount' => 10000,
            'frequency' => 'M', 'no_of_installments' => 10, 'installment_from_date' => '2026-11-01',
        ])->assertOk()->assertJsonStructure(['installment_amount', 'total_repayment']);

        $this->get('/loans/proposals/create?member='.$member->member_no)->assertOk();
        $this->post('/loans/proposals', [
            'member_id' => $member->id, 'loan_product_id' => $product->id,
            'proposal_date' => '2026-10-21', 'loan_purpose' => 'CRUD agriculture',
            'loan_amount' => 10000, 'frequency' => 'M', 'no_of_installments' => 10,
            'installment_from_date' => '2026-11-01',
            'guarantors' => [['name' => 'CRUD Guarantor', 'relation' => 'Brother',
                'phone' => '01710000009', 'address' => 'Talgachi', 'national_id' => '1996999999']],
        ])->assertRedirect()->assertSessionHas('success');

        $proposal = LoanProposal::where('member_id', $member->id)->latest('id')->firstOrFail();
        $this->assertSame('Submitted', $proposal->status);

        $this->get('/loans/proposals')->assertOk();
        $this->post("/loans/proposals/{$proposal->id}/approve")
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame('Approved', $proposal->fresh()->status);

        // The reject path uses a second member: an Approved proposal
        // already counts toward the product's max-concurrent-loans cap,
        // so the same member cannot submit another one (designed guard,
        // verified by the validation error it raises).
        $member2 = $this->makeMember();
        $this->post('/loans/proposals', [
            'member_id' => $member2->id, 'loan_product_id' => $product->id,
            'proposal_date' => '2026-10-21', 'loan_purpose' => 'CRUD reject me',
            'loan_amount' => 5000, 'frequency' => 'M', 'no_of_installments' => 5,
            'installment_from_date' => '2026-11-01',
        ])->assertRedirect()->assertSessionHas('success');
        $rejected = LoanProposal::where('member_id', $member2->id)
            ->where('loan_purpose', 'CRUD reject me')->firstOrFail();
        $this->post("/loans/proposals/{$rejected->id}/reject")
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame('Rejected', $rejected->fresh()->status);

        // Open the account from the approved proposal, then disburse.
        $this->post('/loans/opening', [
            'member_id' => $member->id, 'loan_proposal_id' => $proposal->id,
            'loan_product_id' => $product->id, 'opening_date' => '2026-10-21',
            'loan_purpose' => 'CRUD agriculture', 'loan_amount' => 10000, 'down_payment' => 0,
            'frequency' => 'M', 'no_of_installments' => 10,
            'installment_from_date' => '2026-11-01', 'fees' => [], 'guarantors' => [],
        ])->assertRedirect()->assertSessionHas('success');
        $loan = LoanAccount::where('loan_proposal_id', $proposal->id)->firstOrFail();
        $this->assertGreaterThan(0, $loan->schedules()->count());

        $this->get('/loans/disbursement')->assertOk();
        $this->post("/loans/disbursement/{$loan->id}")
            ->assertRedirect()->assertSessionHas('success');
        $loan->refresh();
        $this->assertContains($loan->status, ['Active', 'Disbursed']);

        // Receive one installment, see it in history, then cancel it.
        $this->get('/loans/receive?member='.$member->member_no)->assertOk();
        $this->post('/loans/receive', [
            'loan_account_id' => $loan->id, 'amount' => $loan->installment_amount,
            'receive_date' => '2026-11-01', 'document_number' => 'CRUD-LR1',
        ])->assertRedirect()->assertSessionHas('success');
        $receive = LoanReceive::where('loan_account_id', $loan->id)->latest('id')->firstOrFail();
        $this->get('/loans/receive/history')->assertOk();

        $this->post("/loans/receive/{$receive->id}/cancel", ['reason' => 'CRUD wrong entry'])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertNotNull($receive->fresh()->cancel_at);

        // Due-penalty setup for the loan.
        $this->get('/loans/due-penalty')->assertOk();
        $this->post("/loans/due-penalty/{$loan->id}", ['penal_charge' => 25])
            ->assertRedirect()->assertSessionHas('success');
        $setup = DuePenaltySetup::where('loan_account_id', $loan->id)->firstOrFail();
        $this->assertSame('25.00', (string) $setup->penal_charge);
    }

    public function test_loan_adjustments_reschedule_waiver_penalty_writeoff(): void
    {
        $member = $this->makeMember();
        $loan = $this->openAndDisburse($member);

        // Reschedule: respread the outstanding over 6 new installments.
        $this->get("/loans/reschedule?loan_id={$loan->id}")->assertOk();
        $this->post('/loans/reschedule', [
            'loan_id' => $loan->id, 'reschedule_date' => '2026-10-21',
            'additional_profit' => 0, 'frequency' => 'M', 'no_of_installments' => 6,
            'first_installment_date' => '2026-12-01', 'fees' => [],
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertSame(6, $loan->fresh()->no_of_installments);

        // Waiver, then cancel the waiver (designed reversal).
        $this->get("/loans/waiver?loan_id={$loan->id}")->assertOk();
        $this->post('/loans/waiver', [
            'loan_id' => $loan->id, 'waiver_date' => '2026-10-21',
            'principal_waive' => 0, 'profit_waive' => 100, 'charge_waive' => 0,
            'comments' => 'CRUD waiver',
        ])->assertRedirect()->assertSessionHas('success');
        $waiver = LoanWaiver::where('loan_account_id', $loan->id)->latest('id')->firstOrFail();
        $this->post("/loans/waiver/{$waiver->id}/cancel")
            ->assertRedirect()->assertSessionHas('success');
        $this->assertNotNull($waiver->fresh()->cancel_at);

        // Additional penalty, then cancel it.
        $this->get("/loans/penalty?loan_id={$loan->id}")->assertOk();
        $this->post('/loans/penalty', [
            'loan_id' => $loan->id, 'entry_date' => '2026-10-21',
            'additional_profit' => 0, 'penalty_amount' => 200, 'comments' => 'CRUD penalty',
        ])->assertRedirect()->assertSessionHas('success');
        $penalty = LoanPenalty::where('loan_account_id', $loan->id)->latest('id')->firstOrFail();
        $this->post("/loans/penalty/{$penalty->id}/cancel")
            ->assertRedirect()->assertSessionHas('success');
        $this->assertNotNull($penalty->fresh()->cancel_at);

        // Write-off on a separate loan, then a recovery collection.
        $member2 = $this->makeMember();
        $loan2 = $this->openAndDisburse($member2, ['loan_amount' => 5000, 'no_of_installments' => 5]);
        $this->get("/loans/writeoff?loan_id={$loan2->id}")->assertOk();
        $this->post('/loans/writeoff', [
            'loan_id' => $loan2->id, 'writeoff_date' => '2026-10-21', 'comments' => 'CRUD write-off',
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertSame('WrittenOff', $loan2->fresh()->status);
        $writeoff = Writeoff::where('loan_account_id', $loan2->id)->firstOrFail();

        $this->post('/loans/writeoff/collect', [
            'loan_id' => $loan2->id, 'recovery_date' => '2026-10-21',
            'principal_recovery' => 500, 'profit_recovery' => 0, 'charge_recovery' => 0,
            'comments' => 'CRUD recovery',
        ])->assertRedirect()->assertSessionHas('success');
        // Recoveries are their own records, not columns on the write-off.
        $collection = \App\Models\WriteoffCollection::where('loan_account_id', $loan2->id)->firstOrFail();
        $this->assertSame('500.00', (string) $collection->principal_recovery);
        $this->assertSame('500.00', (string) $collection->total_recovery);
        $this->assertGreaterThan(0, (float) $writeoff->total_amount);
    }

    public function test_llp_and_loan_report_pages_render(): void
    {
        $member = $this->makeMember();
        $this->openAndDisburse($member);

        $this->get('/reports/llp?run=1&report_date=2026-10-21')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Reports/Llp'));
        $this->post('/reports/llp/post', ['report_date' => '2026-10-21'])
            ->assertRedirect()->assertSessionHas('success');

        foreach (['member_loan_balance', 'loan_disbursement_register', 'loan_collection_register',
            'samity_loan_outstanding', 'fully_paid_register', 'written_off_register'] as $key) {
            $this->get("/reports/loans?report={$key}&run=1&loan_type=active")
                ->assertOk()
                ->assertInertia(fn ($p) => $p->component('Reports/LoanReports'));
        }

        $pdf = $this->get('/reports/loans/member_loan_balance/pdf');
        $pdf->assertOk();
        $this->assertStringContainsString('pdf', (string) $pdf->headers->get('content-type'));
    }
}
