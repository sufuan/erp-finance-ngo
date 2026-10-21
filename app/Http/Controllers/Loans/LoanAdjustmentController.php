<?php

namespace App\Http\Controllers\Loans;

use App\Http\Controllers\Controller;
use App\Models\LoanAccount;
use App\Models\LoanPenalty;
use App\Models\LoanWaiver;
use App\Models\Writeoff;
use App\Models\WriteoffCollection;
use App\Services\BusinessDayService;
use App\Services\LoanAdjustmentService;
use App\Services\LoanMoneyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Step 19 — reschedule, waiver, additional penalty, write-off pages. */
class LoanAdjustmentController extends Controller
{
    public function __construct(
        private LoanAdjustmentService $adjust,
        private LoanMoneyService $money,
        private BusinessDayService $businessDays,
    ) {}

    private function loanPayload(LoanAccount $loan, Request $request): array
    {
        $loan->load(['member.samity', 'product', 'schedules']);

        return [
            'id' => $loan->id, 'loan_number' => $loan->loan_number, 'status' => $loan->status,
            'borrower' => $loan->member?->name ?? $loan->employee?->name ?? '—',
            'samity' => $loan->member?->samity?->name,
            'loan_amount' => $loan->loan_amount, 'profit_amount' => $loan->profit_amount,
            'total_repayment' => $loan->total_repayment, 'frequency' => $loan->frequency,
            'no_of_installments' => $loan->no_of_installments,
            'installment_amount' => $loan->installment_amount,
            'outstanding' => $this->money->outstanding($loan),
            'penalty_due' => $this->money->penaltyDue($loan),
            'business_date' => $this->businessDays->currentDate($loan->branch_id),
            'schedules' => $loan->schedules->map(fn ($s) => [
                'id' => $s->id, 'installment_no' => $s->installment_no,
                'due_date' => $s->due_date?->toDateString(),
                'principal_due' => $s->principal_due, 'profit_due' => $s->profit_due,
                'total_due' => $s->total_due, 'paid_principal' => $s->paid_principal,
                'paid_profit' => $s->paid_profit, 'paid_penalty' => $s->paid_penalty,
                'status' => $s->status, 'superseded' => $s->superseded_by !== null,
            ])->values(),
            'waivers' => LoanWaiver::where('loan_account_id', $loan->id)->latest('id')->get()
                ->map(fn ($w) => ['id' => $w->id, 'waiver_date' => $w->waiver_date?->toDateString(), 'principal_waive' => $w->principal_waive, 'profit_waive' => $w->profit_waive, 'charge_waive' => $w->charge_waive, 'total_waiver' => $w->total_waiver, 'cancelled' => $w->cancel_at !== null]),
            'penalties' => LoanPenalty::where('loan_account_id', $loan->id)->latest('id')->get()
                ->map(fn ($p) => ['id' => $p->id, 'entry_date' => $p->entry_date?->toDateString(), 'additional_profit' => $p->additional_profit, 'penalty_amount' => $p->penalty_amount, 'cancelled' => $p->cancel_at !== null]),
            'writeoff' => ($w = Writeoff::where('loan_account_id', $loan->id)->latest('id')->first()) ? ['writeoff_date' => $w->writeoff_date?->toDateString(), 'total_amount' => $w->total_amount, 'principal' => $w->principal, 'profit' => $w->profit, 'charge' => $w->charge] : null,
            'collections' => WriteoffCollection::where('loan_account_id', $loan->id)->latest('id')->get()
                ->map(fn ($c) => ['id' => $c->id, 'recovery_date' => $c->recovery_date?->toDateString(), 'total_recovery' => $c->total_recovery, 'principal_recovery' => $c->principal_recovery, 'profit_recovery' => $c->profit_recovery, 'charge_recovery' => $c->charge_recovery]),
        ];
    }

    public function rescheduleForm(Request $request): Response
    {
        $loan = LoanAccount::findOrFail($request->integer('loan_id'));

        return Inertia::render('Loans/Reschedule', [
            'loan' => $this->loanPayload($loan, $request),
            'freq_options' => ['D', 'W', 'M', 'Q', 'H', 'Y', 'F-15days'],
            'fee_types' => ['form', 'health', 'risk', 'document', 'emi_locker', 'others'],
        ]);
    }

    public function reschedule(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'loan_id' => ['required', 'integer'],
            'reschedule_date' => ['required', 'date'],
            'additional_profit' => ['required', 'numeric', 'min:0'],
            'frequency' => ['required', 'string'],
            'no_of_installments' => ['required', 'integer', 'min:1'],
            'first_installment_date' => ['required', 'date'],
            'fees' => ['nullable', 'array'],
            'fees.*' => ['nullable', 'numeric', 'min:0'],
        ]);
        $loan = LoanAccount::findOrFail($data['loan_id']);
        $this->adjust->reschedule($loan, $data, $request->user());

        return redirect()->route('loans.reschedule.form', ['loan_id' => $loan->id])
            ->with('success', __('saved_successfully'));
    }

    public function waiverForm(Request $request): Response
    {
        $loan = LoanAccount::findOrFail($request->integer('loan_id'));

        return Inertia::render('Loans/Waiver', ['loan' => $this->loanPayload($loan, $request)]);
    }

    public function waive(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'loan_id' => ['required', 'integer'],
            'waiver_date' => ['required', 'date'],
            'principal_waive' => ['required', 'numeric', 'min:0'],
            'profit_waive' => ['required', 'numeric', 'min:0'],
            'charge_waive' => ['required', 'numeric', 'min:0'],
            'comments' => ['nullable', 'string'],
        ]);
        $loan = LoanAccount::findOrFail($data['loan_id']);
        $this->adjust->waive($loan, $data, $request->user());

        return redirect()->route('loans.waiver.form', ['loan_id' => $loan->id])
            ->with('success', __('saved_successfully'));
    }

    public function cancelWaiver(Request $request, LoanWaiver $waiver): RedirectResponse
    {
        $this->adjust->cancelWaiver($waiver, $request->user());

        return back()->with('success', __('saved_successfully'));
    }

    public function penaltyForm(Request $request): Response
    {
        $loan = LoanAccount::findOrFail($request->integer('loan_id'));

        return Inertia::render('Loans/AdditionalPenalty', ['loan' => $this->loanPayload($loan, $request)]);
    }

    public function addPenalty(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'loan_id' => ['required', 'integer'],
            'entry_date' => ['required', 'date'],
            'additional_profit' => ['required', 'numeric', 'min:0'],
            'penalty_amount' => ['required', 'numeric', 'min:0'],
            'comments' => ['nullable', 'string'],
        ]);
        $loan = LoanAccount::findOrFail($data['loan_id']);
        $this->adjust->addPenalty($loan, $data, $request->user());

        return redirect()->route('loans.penalty.form', ['loan_id' => $loan->id])
            ->with('success', __('saved_successfully'));
    }

    public function cancelPenalty(Request $request, LoanPenalty $penalty): RedirectResponse
    {
        $this->adjust->cancelPenalty($penalty, $request->user());

        return back()->with('success', __('saved_successfully'));
    }

    public function writeoffForm(Request $request): Response
    {
        $loan = LoanAccount::findOrFail($request->integer('loan_id'));

        return Inertia::render('Loans/WriteOff', ['loan' => $this->loanPayload($loan, $request)]);
    }

    public function writeoff(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'loan_id' => ['required', 'integer'],
            'writeoff_date' => ['required', 'date'],
            'comments' => ['nullable', 'string'],
        ]);
        $loan = LoanAccount::findOrFail($data['loan_id']);
        $this->adjust->writeoff($loan, $data, $request->user());

        return redirect()->route('loans.writeoff.form', ['loan_id' => $loan->id])
            ->with('success', __('saved_successfully'));
    }

    public function collectWriteoff(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'loan_id' => ['required', 'integer'],
            'recovery_date' => ['required', 'date'],
            'principal_recovery' => ['required', 'numeric', 'min:0'],
            'profit_recovery' => ['required', 'numeric', 'min:0'],
            'charge_recovery' => ['required', 'numeric', 'min:0'],
            'comments' => ['nullable', 'string'],
        ]);
        $loan = LoanAccount::findOrFail($data['loan_id']);
        $this->adjust->collectWriteoff($loan, $data, $request->user());

        return redirect()->route('loans.writeoff.form', ['loan_id' => $loan->id])
            ->with('success', __('saved_successfully'));
    }
}
