<?php

namespace App\Http\Controllers\Accounts;

use App\Http\Controllers\Controller;
use App\Models\BillingType;
use App\Models\Branch;
use App\Models\GroupTransaction;
use App\Models\Ledger;
use App\Models\LedgerGroup;
use App\Models\ReceivePaymentSetup;
use App\Models\RecurringSetup;
use App\Services\AccountsService;
use App\Services\BranchAccessService;
use App\Services\BusinessDayService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module 9 / Steps 26–28 — Account Management masters: chart-of-accounts
 * opening & list, billing types, receive/payment setups, recurring
 * ledger amounts, and ledger groups with their group transactions.
 * Small masters share one parameterized page (the HRM-masters pattern).
 */
class AccountMasterController extends Controller
{
    public function __construct(
        private AccountsService $accounts,
        private BusinessDayService $businessDays,
        private BranchAccessService $branchAccess,
    ) {}

    private function activeBranch(Request $request): Branch
    {
        return $request->attributes->get('active_branch')
            ?? Branch::where('type', 'Head Office')->firstOrFail();
    }

    private function ledgerOptions()
    {
        return Ledger::where('is_active', true)->orderBy('ledger_code')->get()
            ->map(fn (Ledger $l) => ['id' => $l->id, 'code' => $l->ledger_code, 'name' => $l->name_en, 'group' => $l->ledger_group, 'is_control' => $l->is_control])->all();
    }

    private function manualLedgerOptions()
    {
        return $this->accounts->manualLedgers()
            ->map(fn (Ledger $l) => ['id' => $l->id, 'code' => $l->ledger_code, 'name' => $l->name_en, 'name_bn' => $l->name_bn, 'group' => $l->ledger_group])->all();
    }

    // -- Chart of accounts ----------------------------------------------------

    public function ledgerIndex(Request $request): Response
    {
        $branchIds = $this->branchAccess->accessibleIds($request->user());
        $branchId = $request->filled('branch_id')
            ? (int) $request->query('branch_id')
            : $this->activeBranch($request)->id;
        abort_unless($this->branchAccess->canAccess($request->user(), $branchId), 403);

        $balances = app(\App\Services\AccountReportService::class)->ledgerBalances([$branchId], null, null);
        $balanceById = collect($balances)->keyBy('ledger_id');

        return Inertia::render('Accounts/Ledgers', [
            'ledgers' => Ledger::orderBy('ledger_code')->get()->map(fn (Ledger $l) => [
                'id' => $l->id, 'code' => $l->ledger_code, 'name' => $l->name_en, 'name_bn' => $l->name_bn,
                'group' => $l->ledger_group, 'is_control' => $l->is_control, 'is_active' => $l->is_active,
                'balance' => $balanceById[$l->id]['balance'] ?? '0.00',
            ])->all(),
            'branchId' => $branchId,
            'branches' => $this->branchAccess->visibleBranches($request->user())
                ->map(fn (Branch $b) => ['id' => $b->id, 'code' => $b->code, 'name' => $b->name])->all(),
            'suggestedCode' => $this->suggestCode($request->query('group', 'Asset')),
            'filterGroup' => (string) $request->query('group', ''),
        ]);
    }

    private function suggestCode(string $group): string
    {
        $prefix = ['Asset' => '1', 'Liability' => '2', 'Equity' => '3', 'Income' => '4', 'Expense' => '5'][$group] ?? '1';
        $max = Ledger::where('ledger_code', 'like', $prefix.'%')->max('ledger_code');

        return $max ? (string) ((int) $max + 10) : $prefix.'010';
    }

    public function ledgerStore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ledger_code' => ['required', 'string', 'max:10', 'unique:ledgers,ledger_code'],
            'name_en' => ['required', 'string', 'max:120'],
            'name_bn' => ['nullable', 'string', 'max:120'],
            'ledger_group' => ['required', 'in:Asset,Liability,Equity,Income,Expense'],
            'parent_id' => ['nullable', 'integer', 'exists:ledgers,id'],
            'opening_balance' => ['nullable', 'numeric', 'min:0'],
        ]);
        $ledger = Ledger::create(collect($data)->except('opening_balance')->all() + [
            // name_bn is NOT NULL in the chart; an omitted Bangla name
            // falls back to the English one instead of failing the insert.
            'name_bn' => $data['name_bn'] ?? $data['name_en'],
            'is_control' => false, 'is_active' => true,
        ]);

        // A non-zero opening balance is booked as a Journal against
        // Retained Earnings (3020) in the ACTIVE branch — debit-normal
        // groups (Asset/Expense) debit the new ledger, credit-normal
        // groups credit it. The voucher follows the normal Pending →
        // authorize lifecycle like every other journal.
        $opening = (string) ($data['opening_balance'] ?? '0');
        if ((float) $opening > 0) {
            $retained = Ledger::where('ledger_code', '3020')->firstOrFail();
            $debitNormal = in_array($data['ledger_group'], ['Asset', 'Expense'], true);
            try {
                $this->accounts->ledgerTransaction(
                    $request->user(), $this->activeBranch($request)->id,
                    $debitNormal ? $ledger->id : $retained->id,
                    $debitNormal ? $retained->id : $ledger->id,
                    $opening, 'Opening balance — '.$ledger->ledger_code,
                );
            } catch (DomainException $e) {
                return back()->with('error', $e->getMessage());
            }
        }

        return back()->with('success', __('saved'));
    }

    public function ledgerUpdate(Request $request, Ledger $ledger): RedirectResponse
    {
        $data = $request->validate([
            'name_en' => ['required', 'string', 'max:120'],
            'name_bn' => ['nullable', 'string', 'max:120'],
            'is_active' => ['required', 'boolean'],
        ]);
        $ledger->update($data);

        return back()->with('success', __('saved'));
    }

    // -- Billing types & receive/payment setups & recurring -------------------

    private const ENTITIES = [
        'billing-types' => [
            'page' => 'billing', 'model' => BillingType::class,
            'fields' => ['name_en', 'name_bn', 'is_active'],
            'rules' => [
                'name_en' => ['required', 'string', 'max:120'],
                'name_bn' => ['nullable', 'string', 'max:120'],
                'is_active' => ['nullable', 'boolean'],
            ],
        ],
        'setups' => [
            'page' => 'setups', 'model' => ReceivePaymentSetup::class,
            'fields' => ['billing_type_id', 'name', 'ledger_id', 'side', 'is_active'],
            'rules' => [
                'billing_type_id' => ['nullable', 'integer', 'exists:billing_types,id'],
                'name' => ['required', 'string', 'max:120'],
                'ledger_id' => ['required', 'integer', 'exists:ledgers,id'],
                'side' => ['required', 'in:Receive,Payment'],
                'is_active' => ['nullable', 'boolean'],
            ],
        ],
    ];

    public function masterIndex(Request $request, string $entity): Response
    {
        abort_unless(isset(self::ENTITIES[$entity]), 404);
        if ($entity === 'billing-types') {
            $rows = BillingType::orderBy('name_en')->get()->map(fn (BillingType $b) => [
                'id' => $b->id, 'name_en' => $b->name_en, 'name_bn' => $b->name_bn, 'is_active' => $b->is_active,
            ])->all();
        } else {
            $rows = ReceivePaymentSetup::with(['ledger:id,ledger_code,name_en', 'billingType:id,name_en'])
                ->orderBy('name')->get()->map(fn (ReceivePaymentSetup $s) => [
                    'id' => $s->id, 'name' => $s->name, 'side' => $s->side, 'is_active' => $s->is_active,
                    'ledger_id' => $s->ledger_id, 'ledger' => $s->ledger?->ledger_code.' — '.$s->ledger?->name_en,
                    'billing_type_id' => $s->billing_type_id, 'billing_type' => $s->billingType?->name_en,
                ])->all();
        }

        return Inertia::render('Accounts/Masters', [
            'entity' => $entity, 'rows' => $rows,
            'ledgers' => $this->manualLedgerOptions(),
            'billingTypes' => BillingType::where('is_active', true)->orderBy('name_en')->get(['id', 'name_en'])->all(),
        ]);
    }

    public function masterStore(Request $request, string $entity): RedirectResponse
    {
        abort_unless(isset(self::ENTITIES[$entity]), 404);
        $config = self::ENTITIES[$entity];
        $data = $request->validate($config['rules']);
        $model = $config['model'];
        $model::create(collect($data)->only($config['fields'])->all() + ['is_active' => $data['is_active'] ?? true]);

        return back()->with('success', __('saved'));
    }

    public function masterUpdate(Request $request, string $entity, int $id): RedirectResponse
    {
        abort_unless(isset(self::ENTITIES[$entity]), 404);
        $config = self::ENTITIES[$entity];
        $data = $request->validate($config['rules']);
        $model = $config['model'];
        $model::findOrFail($id)->update(collect($data)->only($config['fields'])->all());

        return back()->with('success', __('saved'));
    }

    public function masterDestroy(string $entity, int $id): RedirectResponse
    {
        abort_unless(isset(self::ENTITIES[$entity]), 404);
        $model = self::ENTITIES[$entity]['model'];
        $model::findOrFail($id)->update(['is_active' => false]);

        return back()->with('success', __('saved'));
    }

    // -- Recurring ledger amounts ----------------------------------------------

    public function recurringIndex(Request $request): Response
    {
        $branch = $this->activeBranch($request);
        $businessDate = $this->businessDays->currentDate($branch->id);
        $branchIds = $this->branchAccess->accessibleIds($request->user());

        return Inertia::render('Accounts/Recurring', [
            'rows' => RecurringSetup::with(['debitLedger:id,ledger_code,name_en', 'creditLedger:id,ledger_code,name_en'])
                ->whereIn('branch_id', $branchIds)->orderBy('next_run_date')->get()->map(fn (RecurringSetup $s) => [
                    'id' => $s->id, 'name' => $s->name, 'amount' => (string) $s->amount,
                    'frequency' => $s->frequency, 'next_run_date' => $s->next_run_date->toDateString(),
                    'last_run_date' => $s->last_run_date?->toDateString(), 'is_active' => $s->is_active,
                    'debit' => $s->debitLedger?->ledger_code.' — '.$s->debitLedger?->name_en,
                    'credit' => $s->creditLedger?->ledger_code.' — '.$s->creditLedger?->name_en,
                    'debit_ledger_id' => $s->debit_ledger_id, 'credit_ledger_id' => $s->credit_ledger_id,
                    'due' => $s->is_active && $s->next_run_date->toDateString() <= $businessDate,
                    'branch_id' => $s->branch_id,
                ])->all(),
            'ledgers' => $this->manualLedgerOptions(),
            'businessDate' => $businessDate,
            'branch' => ['id' => $branch->id, 'code' => $branch->code, 'name' => $branch->name],
        ]);
    }

    public function recurringStore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'debit_ledger_id' => ['required', 'integer', 'exists:ledgers,id', Rule::notIn([request('credit_ledger_id')])],
            'credit_ledger_id' => ['required', 'integer', 'exists:ledgers,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'frequency' => ['required', 'in:Daily,Weekly,Monthly'],
            'next_run_date' => ['required', 'date'],
        ]);
        RecurringSetup::create($data + [
            'branch_id' => $this->activeBranch($request)->id, 'is_active' => true, 'entry_by' => $request->user()->id,
        ]);

        return back()->with('success', __('saved'));
    }

    public function recurringUpdate(Request $request, RecurringSetup $setup): RedirectResponse
    {
        abort_unless($this->branchAccess->canAccess($request->user(), $setup->branch_id), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'debit_ledger_id' => ['required', 'integer', 'exists:ledgers,id'],
            'credit_ledger_id' => ['required', 'integer', 'exists:ledgers,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'frequency' => ['required', 'in:Daily,Weekly,Monthly'],
            'next_run_date' => ['required', 'date'],
            'is_active' => ['required', 'boolean'],
        ]);
        if ($data['debit_ledger_id'] === $data['credit_ledger_id']) {
            return back()->with('error', __('ledger_same_both_sides'));
        }
        $setup->update($data);

        return back()->with('success', __('saved'));
    }

    public function recurringPost(Request $request, RecurringSetup $setup): RedirectResponse
    {
        abort_unless($this->branchAccess->canAccess($request->user(), $setup->branch_id), 403);
        abort_unless($setup->is_active, 422);
        try {
            $voucher = $this->accounts->postRecurring($setup, $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('saved').' — '.$voucher->batch_number);
    }

    // -- Ledger groups & group transactions --------------------------------------

    public function groupIndex(Request $request): Response
    {
        $branchIds = $this->branchAccess->accessibleIds($request->user());

        return Inertia::render('Accounts/Groups', [
            'groups' => LedgerGroup::with('lines.ledger:id,ledger_code,name_en')
                ->whereIn('branch_id', $branchIds)->orderBy('name')->get()->map(fn (LedgerGroup $g) => [
                    'id' => $g->id, 'name' => $g->name, 'is_active' => $g->is_active, 'branch_id' => $g->branch_id,
                    'lines' => $g->lines->map(fn ($line) => [
                        'id' => $line->id, 'ledger_id' => $line->ledger_id, 'side' => $line->side,
                        'ledger' => $line->ledger?->ledger_code.' — '.$line->ledger?->name_en,
                    ])->all(),
                ])->all(),
            'ledgers' => $this->manualLedgerOptions(),
            'branch' => ['id' => $this->activeBranch($request)->id],
        ]);
    }

    public function groupStore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.ledger_id' => ['required', 'integer', 'exists:ledgers,id'],
            'lines.*.side' => ['required', 'in:Debit,Credit'],
        ]);
        $group = LedgerGroup::create([
            'branch_id' => $this->activeBranch($request)->id, 'name' => $data['name'], 'is_active' => true,
        ]);
        foreach ($data['lines'] as $line) {
            $group->lines()->create(['ledger_id' => $line['ledger_id'], 'side' => $line['side']]);
        }

        return back()->with('success', __('saved'));
    }

    public function groupUpdate(Request $request, LedgerGroup $group): RedirectResponse
    {
        abort_unless($this->branchAccess->canAccess($request->user(), $group->branch_id), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'is_active' => ['required', 'boolean'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.ledger_id' => ['required', 'integer', 'exists:ledgers,id'],
            'lines.*.side' => ['required', 'in:Debit,Credit'],
        ]);
        $group->update(['name' => $data['name'], 'is_active' => $data['is_active']]);
        $group->lines()->delete();
        foreach ($data['lines'] as $line) {
            $group->lines()->create(['ledger_id' => $line['ledger_id'], 'side' => $line['side']]);
        }

        return back()->with('success', __('saved'));
    }

    public function groupDestroy(LedgerGroup $group): RedirectResponse
    {
        $group->update(['is_active' => false]);

        return back()->with('success', __('saved'));
    }

    public function groupTransactionForm(Request $request): Response
    {
        $branchIds = $this->branchAccess->accessibleIds($request->user());
        $group = null;
        if ($request->filled('group_id')) {
            $found = LedgerGroup::with('lines.ledger:id,ledger_code,name_en')
                ->whereIn('branch_id', $branchIds)->find((int) $request->query('group_id'));
            if ($found) {
                $group = [
                    'id' => $found->id, 'name' => $found->name,
                    'lines' => $found->lines->map(fn ($line) => [
                        'id' => $line->id, 'side' => $line->side,
                        'ledger' => $line->ledger?->ledger_code.' — '.$line->ledger?->name_en,
                    ])->all(),
                ];
            }
        }

        return Inertia::render('Accounts/GroupTransaction', [
            'groups' => LedgerGroup::whereIn('branch_id', $branchIds)->where('is_active', true)
                ->orderBy('name')->get(['id', 'name'])->all(),
            'group' => $group,
            'groupQuery' => $request->integer('group_id') ?: null,
            'businessDate' => $this->businessDays->currentDate($this->activeBranch($request)->id),
        ]);
    }

    public function groupTransactionStore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'group_id' => ['required', 'integer', 'exists:ledger_groups,id'],
            'amounts' => ['required', 'array'],
            'amounts.*' => ['nullable', 'numeric', 'min:0'],
            'comments' => ['nullable', 'string', 'max:500'],
        ]);
        $group = LedgerGroup::findOrFail($data['group_id']);
        abort_unless($this->branchAccess->canAccess($request->user(), $group->branch_id), 403);
        try {
            $voucher = $this->accounts->groupTransaction(
                $request->user(), $group,
                collect($data['amounts'])->map(fn ($v) => (string) ($v ?? '0'))->all(),
                $data['comments'] ?? null,
            );
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('saved').' — '.$voucher->batch_number);
    }

    public function groupTransactionList(Request $request): Response
    {
        $branchIds = $this->branchAccess->accessibleIds($request->user());

        return Inertia::render('Accounts/GroupTransactions', [
            'rows' => GroupTransaction::with(['group:id,name', 'voucher:id,batch_number,total_amount,status,transaction_date', 'enteredBy:id,name'])
                ->whereIn('branch_id', $branchIds)->orderByDesc('id')->limit(200)->get()->map(fn (GroupTransaction $row) => [
                    'id' => $row->id, 'group' => $row->group?->name,
                    'voucher_no' => $row->voucher?->batch_number, 'voucher_id' => $row->voucher_id,
                    'amount' => (string) ($row->voucher?->total_amount ?? '0'),
                    'status' => $row->voucher?->status,
                    'date' => $row->voucher?->transaction_date?->toDateString(),
                    'entry_by' => $row->enteredBy?->name,
                ])->all(),
        ]);
    }
}
