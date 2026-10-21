<?php

namespace App\Http\Controllers\Loans;

use App\Http\Controllers\Controller;
use App\Models\DepositAccount;
use App\Models\LoanAccount;
use App\Models\LoanReceive;
use App\Models\Member;
use App\Models\Samity;
use App\Services\AuditService;
use App\Services\LoanMoneyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Step 18 — disbursement list, installment receive (+ manual/staff), cancel, history. */
class LoanMoneyController extends Controller
{
    public function __construct(private LoanMoneyService $money) {}

    // ---- Disbursement ----

    public function disbursementList(Request $request): Response
    {
        $query = LoanAccount::with(['member', 'product'])
            ->where('status', 'Opened')->orderByDesc('id');
        if ($request->filled('samity_id')) {
            $query->whereHas('member', fn ($q) => $q->where('samity_id', $request->integer('samity_id')));
        }
        if ($request->filled('member')) {
            $needle = trim((string) $request->query('member'));
            $query->whereHas('member', fn ($q) => $q->where('member_no', $needle)
                ->orWhere('id', ctype_digit($needle) ? (int) $needle : -1));
        }
        if ($request->filled('from_date')) {
            $query->where('opening_date', '>=', $request->query('from_date'));
        }
        if ($request->filled('to_date')) {
            $query->where('opening_date', '<=', $request->query('to_date'));
        }

        return Inertia::render('Loans/DisbursementList', [
            'loans' => $query->limit(100)->get()->map(fn (LoanAccount $l) => [
                'id' => $l->id, 'loan_number' => $l->loan_number,
                'samity' => $l->member?->samity ? $l->member->samity->samity_code.' — '.$l->member->samity->name : null,
                'member' => $l->member ? $l->member->member_no.' — '.$l->member->name : ($l->employee?->name),
                'loan_amount' => (string) $l->loan_amount, 'total_repayment' => (string) $l->total_repayment,
                'profit_rate' => (string) $l->profit_rate,
                'installment_from_date' => $l->installment_from_date?->toDateString(),
                'no_of_installments' => $l->no_of_installments, 'status' => $l->status,
            ])->all(),
            'samities' => Samity::orderBy('name')->get(['id', 'samity_code', 'name'])->all(),
            'filters' => $request->only(['samity_id', 'member', 'from_date', 'to_date']),
        ]);
    }

    public function disburse(Request $request, LoanAccount $loan, AuditService $audit): RedirectResponse
    {
        $this->money->disburse($loan, $request->user());
        $audit->log($request->user()->id, 'loan.disbursed', 'loan_accounts', $loan->id, null, ['amount' => $loan->loan_amount]);

        return back()->with('success', __('saved'));
    }

    // ---- Receive ----

    public function receiveForm(Request $request): Response
    {
        $mode = (string) $request->query('mode', 'auto'); // auto | manual | staff
        $member = $this->findMember($request);
        $loan = $request->filled('loan_id') ? LoanAccount::find($request->integer('loan_id')) : null;
        $loans = collect();
        if ($mode === 'staff') {
            $loans = LoanAccount::with('employee')->whereNotNull('employee_id')
                ->whereIn('status', ['Disbursed', 'Active'])->orderByDesc('id')->limit(50)->get();
        } elseif ($member) {
            $loans = LoanAccount::where('member_id', $member->id)
                ->whereIn('status', ['Disbursed', 'Active'])->orderByDesc('id')->get();
        }

        return Inertia::render('Loans/InstallmentReceive', [
            'mode' => $mode,
            'member' => $member ? ['id' => $member->id, 'member_no' => $member->member_no, 'name' => $member->name] : null,
            'memberQuery' => (string) $request->query('member', ''),
            'loans' => $loans->map(fn (LoanAccount $l) => [
                'id' => $l->id, 'loan_number' => $l->loan_number,
                'label' => $l->loan_number.' · '.($l->member?->name ?? $l->employee?->name).' · '.$l->loan_amount,
                'installment_amount' => (string) $l->installment_amount,
                'status' => $l->status,
            ])->all(),
            'loanId' => $loan?->id,
            'snapshot' => $loan ? $this->snapshot($loan) : null,
            'depositAccounts' => $member ? DepositAccount::with('product:id,code')
                ->where('member_id', $member->id)->whereNull('closing_date')->where('is_active', true)
                ->get()->map(fn (DepositAccount $a) => [
                    'id' => $a->id, 'account_number' => $a->account_number,
                    'product' => $a->product?->code, 'current_balance' => (string) $a->current_balance,
                ])->all() : [],
        ]);
    }

    public function storeReceive(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'loan_account_id' => ['required', 'integer', 'exists:loan_accounts,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'receive_date' => ['nullable', 'date'],
            'document_number' => ['nullable', 'string', 'max:100'],
            'comments' => ['nullable', 'string'],
            'penalty_charge' => ['nullable', 'numeric', 'min:0'],
            'transfer_from_deposit' => ['nullable', 'boolean'],
            'deposit_account_id' => ['nullable', 'integer', 'exists:deposit_accounts,id'],
            'manual_investment' => ['nullable', 'numeric', 'min:0'],
            'manual_profit' => ['nullable', 'numeric', 'min:0'],
            'manual_penalty' => ['nullable', 'numeric', 'min:0'],
        ]);
        $manual = null;
        if ($request->filled('manual_investment') || $request->filled('manual_profit') || $request->filled('manual_penalty')) {
            $manual = [
                'investment' => (string) ($data['manual_investment'] ?? '0'),
                'profit' => (string) ($data['manual_profit'] ?? '0'),
                'penalty' => (string) ($data['manual_penalty'] ?? '0'),
            ];
        }
        $receive = $this->money->receive(LoanAccount::findOrFail($data['loan_account_id']), [
            'amount' => (string) $data['amount'],
            'receive_date' => $data['receive_date'] ?? null,
            'document_number' => $data['document_number'] ?? null,
            'comments' => $data['comments'] ?? null,
            'penalty_charge' => isset($data['penalty_charge']) ? (string) $data['penalty_charge'] : null,
            'transfer_from_deposit' => (bool) ($data['transfer_from_deposit'] ?? false),
            'deposit_account_id' => $data['deposit_account_id'] ?? null,
            'manual' => $manual,
        ], $request->user());
        $audit->log($request->user()->id, 'loan.received', 'loan_receives', $receive->id, null, ['amount' => $receive->installment_amount]);

        return back()->with('success', __('saved'));
    }

    // ---- Cancel ----

    public function cancelForm(Request $request): Response
    {
        $mode = (string) $request->query('mode', 'member');
        $member = $this->findMember($request);
        $loan = $request->filled('loan_id') ? LoanAccount::find($request->integer('loan_id')) : null;
        $loans = collect();
        if ($mode === 'staff') {
            $loans = LoanAccount::whereNotNull('employee_id')->orderByDesc('id')->limit(50)->get();
        } elseif ($member) {
            $loans = LoanAccount::where('member_id', $member->id)->orderByDesc('id')->get();
        }
        $receives = $loan ? LoanReceive::where('loan_account_id', $loan->id)->orderByDesc('id')->limit(20)->get()
            ->map(fn (LoanReceive $r) => [
                'id' => $r->id, 'receive_date' => $r->receive_date?->toDateString(),
                'installment_amount' => (string) $r->installment_amount,
                'investment_amount' => (string) $r->investment_amount,
                'profit_amount' => (string) $r->profit_amount,
                'penalty_charge' => (string) $r->penalty_charge,
                'cancel_at' => $r->cancel_at?->toDateTimeString(),
                'is_latest' => $r->cancel_at === null && (int) LoanReceive::where('loan_account_id', $loan->id)->whereNull('cancel_at')->max('id') === (int) $r->id,
            ])->all() : [];

        return Inertia::render('Loans/ReceiveCancel', [
            'mode' => $mode,
            'member' => $member ? ['id' => $member->id, 'member_no' => $member->member_no, 'name' => $member->name] : null,
            'memberQuery' => (string) $request->query('member', ''),
            'loans' => $loans->map(fn (LoanAccount $l) => [
                'id' => $l->id, 'loan_number' => $l->loan_number, 'status' => $l->status,
            ])->all(),
            'loanId' => $loan?->id,
            'receives' => $receives,
        ]);
    }

    public function cancelReceive(Request $request, LoanReceive $receive, AuditService $audit): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->money->cancelLatest($receive, $request->user(), $data['reason']);
        $audit->log($request->user()->id, 'loan.receive_cancelled', 'loan_receives', $receive->id, null, ['reason' => $data['reason']]);

        return back()->with('success', __('saved'));
    }

    // ---- History ----

    public function history(Request $request): Response
    {
        $member = $this->findMember($request);
        $loan = $request->filled('loan_id') ? LoanAccount::with(['member.samity', 'product'])->find($request->integer('loan_id')) : null;
        $loans = $member ? LoanAccount::where('member_id', $member->id)->orderByDesc('id')->get() : collect();
        $rows = [];
        if ($loan) {
            $query = LoanReceive::with('allocations.schedule')->where('loan_account_id', $loan->id)->orderBy('id');
            if ($request->filled('from_date')) {
                $query->where('receive_date', '>=', $request->query('from_date'));
            }
            if ($request->filled('to_date')) {
                $query->where('receive_date', '<=', $request->query('to_date'));
            }
            $outstandingPrincipal = \Brick\Math\BigDecimal::of((string) $loan->loan_amount);
            $outstandingProfit = \Brick\Math\BigDecimal::of((string) $loan->profit_amount);
            foreach ($query->get() as $r) {
                if ($r->cancel_at === null) {
                    $outstandingPrincipal = $outstandingPrincipal->minus((string) $r->investment_amount);
                    $outstandingProfit = $outstandingProfit->minus((string) $r->profit_amount);
                }
                $rows[] = [
                    'id' => $r->id, 'branch_code' => $loan->branch?->code,
                    'loan_number' => $loan->loan_number,
                    'member' => $loan->member ? $loan->member->member_no.' — '.$loan->member->name : null,
                    'samity' => $loan->member?->samity ? $loan->member->samity->samity_code.' — '.$loan->member->samity->name : null,
                    'product' => $loan->product?->code, 'profit_rate' => (string) $loan->profit_rate,
                    'installment_amount' => (string) $loan->installment_amount,
                    'receive_date' => $r->receive_date?->toDateString(),
                    'business_date' => $r->business_date?->toDateString(),
                    'investment_amount' => (string) $r->investment_amount,
                    'profit_amount' => (string) $r->profit_amount,
                    'penalty_charge' => (string) $r->penalty_charge,
                    'total_received' => (string) $r->installment_amount,
                    'transfer_from_deposit' => $r->transfer_from_deposit,
                    'document_number' => $r->document_number, 'comments' => $r->comments,
                    'outstanding_principal' => $r->cancel_at === null ? (string) $outstandingPrincipal : '—',
                    'outstanding_profit' => $r->cancel_at === null ? (string) $outstandingProfit : '—',
                    'entry_by' => $r->entry_by, 'entry_at' => $r->entry_at?->toDateTimeString(),
                    'cancel_by' => $r->cancel_by, 'cancel_at' => $r->cancel_at?->toDateTimeString(),
                    'installments' => $r->allocations->map(fn ($a) => $a->schedule?->installment_no)->filter()->implode(','),
                ];
            }
        }

        return Inertia::render('Loans/InstallmentHistory', [
            'member' => $member ? ['id' => $member->id, 'member_no' => $member->member_no, 'name' => $member->name] : null,
            'memberQuery' => (string) $request->query('member', ''),
            'loans' => $loans->map(fn (LoanAccount $l) => ['id' => $l->id, 'loan_number' => $l->loan_number, 'status' => $l->status])->all(),
            'loanId' => $loan?->id,
            'rows' => $rows,
            'filters' => $request->only(['from_date', 'to_date']),
        ]);
    }

    private function snapshot(LoanAccount $loan): array
    {
        $outstanding = $this->money->outstanding($loan);
        $paidPrincipal = $loan->schedules()->sum('paid_principal');
        $paidProfit = $loan->schedules()->sum('paid_profit');

        return [
            'todays_due' => $this->money->todaysDue($loan),
            'penalty_due' => $outstanding['penalty'],
            'loan_amount' => (string) $loan->loan_amount,
            'total_installment' => (string) $loan->total_repayment,
            'paid_installment' => (string) \Brick\Math\BigDecimal::of((string) $paidPrincipal)->plus((string) $paidProfit),
            'due_installment' => $outstanding['total'],
            'expire_date' => $loan->installment_expire_date?->toDateString(),
            'status' => $loan->status,
        ];
    }

    private function findMember(Request $request): ?Member
    {
        $needle = trim((string) $request->query('member', ''));
        if ($needle === '') {
            return null;
        }

        return Member::with('samity')->where('member_no', $needle)
            ->orWhere('id', ctype_digit($needle) ? (int) $needle : -1)->first();
    }
}
