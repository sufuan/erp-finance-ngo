<?php

namespace App\Http\Controllers\Accounts;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Ledger;
use App\Models\ReceivePaymentSetup;
use App\Models\Voucher;
use App\Services\AccountsService;
use App\Services\AuditService;
use App\Services\BranchAccessService;
use App\Services\BusinessDayService;
use App\Services\PostingService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module 9 / Steps 26–27 — financial transaction forms (Account
 * Transaction, Ledger Transaction, Journal Entry, Contra Voucher),
 * the voucher register with detail, the authorization queue, and the
 * cancel workflows. All posting goes through AccountsService →
 * PostingService; authorization/cancel delegate to PostingService so
 * maker-checker and the cancel semantics stay engine-owned.
 */
class VoucherController extends Controller
{
    public function __construct(
        private AccountsService $accounts,
        private PostingService $posting,
        private BusinessDayService $businessDays,
        private BranchAccessService $branchAccess,
    ) {}

    private function activeBranch(Request $request): Branch
    {
        return $request->attributes->get('active_branch')
            ?? Branch::where('type', 'Head Office')->firstOrFail();
    }

    private function formProps(Request $request): array
    {
        $branch = $this->activeBranch($request);

        return [
            'ledgers' => $this->accounts->manualLedgers()->map(fn (Ledger $l) => [
                'id' => $l->id, 'code' => $l->ledger_code, 'name' => $l->name_en,
                'name_bn' => $l->name_bn, 'group' => $l->ledger_group,
            ])->all(),
            'setups' => ReceivePaymentSetup::with('ledger:id,ledger_code,name_en')
                ->where('is_active', true)->orderBy('name')->get()->map(fn (ReceivePaymentSetup $s) => [
                    'id' => $s->id, 'name' => $s->name, 'side' => $s->side,
                    'ledger_id' => $s->ledger_id, 'ledger_code' => $s->ledger?->ledger_code,
                    'ledger_name' => $s->ledger?->name_en,
                ])->all(),
            'businessDate' => $this->businessDays->currentDate($branch->id),
            'branch' => ['id' => $branch->id, 'code' => $branch->code, 'name' => $branch->name],
        ];
    }

    // -- Financial Transaction forms ----------------------------------------

    public function transactionForm(Request $request): Response
    {
        return Inertia::render('Accounts/TransactionForm', [
            ...$this->formProps($request),
            'kind' => 'account',
            'mode' => $request->query('mode') === 'Payment' ? 'Payment' : 'Receive',
            'setupId' => $request->integer('setup_id') ?: null,
        ]);
    }

    public function storeTransaction(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'side' => ['required', 'in:Receive,Payment'],
            'ledger_id' => ['required', 'integer', 'exists:ledgers,id'],
            'setup_id' => ['nullable', 'integer', 'exists:receive_payment_setups,id'],
            'source' => ['required', 'in:cash,bank'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'comments' => ['nullable', 'string', 'max:500'],
        ]);
        try {
            $voucher = $this->accounts->accountTransaction(
                $request->user(), $this->activeBranch($request)->id, $data['side'],
                (int) $data['ledger_id'], $data['source'], (string) $data['amount'], $data['comments'] ?? null,
            );
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('saved').' — '.$voucher->batch_number);
    }

    public function ledgerTransactionForm(Request $request): Response
    {
        return Inertia::render('Accounts/TransactionForm', [
            ...$this->formProps($request), 'kind' => 'ledger',
        ]);
    }

    public function storeLedgerTransaction(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'debit_ledger_id' => ['required', 'integer', 'exists:ledgers,id'],
            'credit_ledger_id' => ['required', 'integer', 'exists:ledgers,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'comments' => ['nullable', 'string', 'max:500'],
        ]);
        try {
            $voucher = $this->accounts->ledgerTransaction(
                $request->user(), $this->activeBranch($request)->id,
                (int) $data['debit_ledger_id'], (int) $data['credit_ledger_id'],
                (string) $data['amount'], $data['comments'] ?? null,
            );
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('saved').' — '.$voucher->batch_number);
    }

    public function journalForm(Request $request): Response
    {
        return Inertia::render('Accounts/TransactionForm', [
            ...$this->formProps($request), 'kind' => 'journal',
        ]);
    }

    public function storeJournal(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.ledger_id' => ['required', 'integer', 'exists:ledgers,id'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'comments' => ['nullable', 'string', 'max:500'],
        ]);
        try {
            $voucher = $this->accounts->journalEntry(
                $request->user(), $this->activeBranch($request)->id,
                collect($data['lines'])->map(fn ($l) => [
                    'ledger_id' => (int) $l['ledger_id'],
                    'debit' => (string) ($l['debit'] ?? '0'), 'credit' => (string) ($l['credit'] ?? '0'),
                ])->all(),
                $data['comments'] ?? null,
            );
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('saved').' — '.$voucher->batch_number);
    }

    public function contraForm(Request $request): Response
    {
        return Inertia::render('Accounts/TransactionForm', [
            ...$this->formProps($request), 'kind' => 'contra',
        ]);
    }

    public function storeContra(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'direction' => ['required', 'in:cash_to_bank,bank_to_cash'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'comments' => ['nullable', 'string', 'max:500'],
        ]);
        try {
            $voucher = $this->accounts->contra(
                $request->user(), $this->activeBranch($request)->id,
                $data['direction'], (string) $data['amount'], $data['comments'] ?? null,
            );
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('saved').' — '.$voucher->batch_number);
    }

    // -- Voucher register -----------------------------------------------------

    private function voucherQuery(Request $request)
    {
        $branchIds = $this->branchAccess->accessibleIds($request->user());
        $query = Voucher::with(['branch:id,code,name', 'enteredBy:id,name'])
            ->whereIn('branch_id', $branchIds)->orderByDesc('id');
        if ($request->filled('branch_id')) {
            abort_unless($this->branchAccess->canAccess($request->user(), (int) $request->query('branch_id')), 403);
            $query->where('branch_id', (int) $request->query('branch_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('voucher_type')) {
            $query->where('voucher_type', $request->query('voucher_type'));
        }
        if ($request->filled('from')) {
            $query->where('transaction_date', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->where('transaction_date', '<=', $request->query('to'));
        }
        if ($request->filled('q')) {
            $query->where('batch_number', 'like', '%'.$request->query('q').'%');
        }

        return $query;
    }

    private function voucherRow(Voucher $v): array
    {
        return [
            'id' => $v->id, 'batch_number' => $v->batch_number, 'voucher_type' => $v->voucher_type,
            'transaction_date' => $v->transaction_date->toDateString(),
            'business_date' => $v->business_date->toDateString(),
            'total_amount' => (string) $v->total_amount, 'status' => $v->status,
            'comments' => $v->comments, 'branch' => $v->branch?->code.' — '.$v->branch?->name,
            'entry_by' => $v->enteredBy?->name, 'backdated' => $v->transaction_date->toDateString() !== $v->business_date->toDateString(),
        ];
    }

    public function index(Request $request): Response
    {
        $preset = (string) $request->query('preset', 'all');
        $query = $this->voucherQuery($request);
        if ($preset === 'cancelled') {
            $query->where('status', 'Cancelled');
        } elseif ($preset === 'backdate') {
            $query->whereColumn('transaction_date', '!=', 'business_date');
        }

        return Inertia::render('Accounts/Vouchers', [
            'preset' => $preset,
            'vouchers' => $query->limit(200)->get()->map(fn (Voucher $v) => $this->voucherRow($v))->all(),
            'filters' => $request->only(['from', 'to', 'status', 'voucher_type', 'q', 'branch_id']),
            'branches' => $this->branchAccess->visibleBranches($request->user())
                ->map(fn (Branch $b) => ['id' => $b->id, 'code' => $b->code, 'name' => $b->name])->all(),
        ]);
    }

    public function show(Request $request, Voucher $voucher): Response
    {
        abort_unless($this->branchAccess->canAccess($request->user(), $voucher->branch_id), 403);
        $voucher->load(['lines.ledger:id,ledger_code,name_en', 'accountTransactions', 'branch:id,code,name', 'enteredBy:id,name']);

        return Inertia::render('Accounts/VoucherShow', [
            'voucher' => $this->voucherRow($voucher) + [
                'auth_by' => $voucher->auth_by, 'cancel_by' => $voucher->cancel_by,
                'lines' => $voucher->lines->map(fn ($line) => [
                    'ledger_code' => $line->ledger?->ledger_code, 'ledger_name' => $line->ledger?->name_en,
                    'debit' => (string) $line->debit, 'credit' => (string) $line->credit,
                    'comments' => $line->comments, 'document_number' => $line->document_number,
                ])->all(),
                'movements' => $voucher->accountTransactions->map(fn ($row) => [
                    'account_type' => $row->account_type, 'account_id' => $row->account_id,
                    'tran_code' => $row->tran_code, 'amount' => (string) $row->amount,
                    'balance_after' => (string) $row->balance_after,
                    'cancelled' => $row->cancel_at !== null,
                ])->all(),
            ],
            'canAuthorize' => $request->user()->can('accounts.authorize'),
            'canCancel' => $request->user()->can('accounts.cancel'),
            'canBreakglass' => $request->user()->can('accounts.breakglass'),
        ]);
    }

    // -- Approval & cancel ----------------------------------------------------

    public function authorization(Request $request): Response
    {
        $mode = $request->query('mode') === 'approval' ? 'approval' : 'queue';
        $branchIds = $this->branchAccess->accessibleIds($request->user());
        $pending = Voucher::with(['branch:id,code,name', 'enteredBy:id,name'])
            ->whereIn('branch_id', $branchIds)->where('status', 'Pending')
            ->orderBy('id')->limit(200)->get();

        return Inertia::render('Accounts/Authorization', [
            'mode' => $mode,
            'vouchers' => $pending->map(fn (Voucher $v) => $this->voucherRow($v))->all(),
            'canAuthorize' => $request->user()->can('accounts.authorize'),
            'canCancel' => $request->user()->can('accounts.cancel'),
            'canBreakglass' => $request->user()->can('accounts.breakglass'),
            'currentUserId' => $request->user()->id,
        ]);
    }

    public function authorize(Request $request, Voucher $voucher, AuditService $audit): RedirectResponse
    {
        abort_unless($this->branchAccess->canAccess($request->user(), $voucher->branch_id), 403);
        $data = $request->validate([
            'break_glass' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        try {
            $this->posting->authorize($voucher, $request->user(), (bool) ($data['break_glass'] ?? false), $data['reason'] ?? null);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('saved'));
    }

    public function cancelForm(Request $request): Response
    {
        $voucher = null;
        if ($request->filled('q')) {
            $found = $this->voucherQuery($request)->where('batch_number', $request->query('q'))->first()
                ?? Voucher::where('batch_number', $request->query('q'))->first();
            if ($found) {
                abort_unless($this->branchAccess->canAccess($request->user(), $found->branch_id), 403);
                $found->load(['branch:id,code,name', 'enteredBy:id,name', 'lines.ledger:id,ledger_code,name_en']);
                $voucher = $this->voucherRow($found) + ['lines' => $found->lines->map(fn ($line) => [
                    'ledger_code' => $line->ledger?->ledger_code, 'ledger_name' => $line->ledger?->name_en,
                    'debit' => (string) $line->debit, 'credit' => (string) $line->credit,
                ])->all()];
            }
        }

        return Inertia::render('Accounts/Cancel', [
            'voucher' => $voucher, 'q' => (string) $request->query('q', ''),
            'searched' => $request->filled('q'),
        ]);
    }

    public function cancel(Request $request, Voucher $voucher): RedirectResponse
    {
        abort_unless($this->branchAccess->canAccess($request->user(), $voucher->branch_id), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        try {
            $this->posting->cancel($voucher, $request->user(), $data['reason']);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('saved'));
    }
}
