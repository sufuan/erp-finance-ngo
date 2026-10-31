<?php

namespace App\Http\Controllers\Accounts;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CashLedger;
use App\Models\CashTransfer;
use App\Models\FundTransferRequest;
use App\Models\User;
use App\Services\AccountReportService;
use App\Services\AccountsService;
use App\Services\BranchAccessService;
use App\Services\BusinessDayService;
use App\Services\PostingService;
use App\Services\TransferService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module 9 / Steps 28–29 — Inter Branch Transaction (fund & product
 * requests with sender-approve / receiver-receive gates) and Cash
 * Management (officer→cashier handovers, cashier balances, day-end
 * counts). Money moves only through TransferService → PostingService.
 */
class TransferController extends Controller
{
    public function __construct(
        private TransferService $transfers,
        private BranchAccessService $branchAccess,
        private BusinessDayService $businessDays,
        private AccountReportService $reports,
        private PostingService $posting,
    ) {}

    private function activeBranch(Request $request): Branch
    {
        return $request->attributes->get('active_branch')
            ?? Branch::where('type', 'Head Office')->firstOrFail();
    }

    private function branchOptions(Request $request): array
    {
        return $this->branchAccess->visibleBranches($request->user())
            ->map(fn (Branch $b) => ['id' => $b->id, 'code' => $b->code, 'name' => $b->name])->all();
    }

    private function requestRow(FundTransferRequest $r): array
    {
        return [
            'id' => $r->id, 'request_no' => $r->request_no, 'kind' => $r->kind,
            'from_branch' => $r->fromBranch?->code.' — '.$r->fromBranch?->name,
            'to_branch' => $r->toBranch?->code.' — '.$r->toBranch?->name,
            'from_branch_id' => $r->from_branch_id, 'to_branch_id' => $r->to_branch_id,
            'amount' => (string) $r->amount, 'item_name' => $r->item_name,
            'quantity' => $r->quantity !== null ? (string) $r->quantity : null,
            'comments' => $r->comments, 'status' => $r->status,
            'requested_by' => $r->requestedBy?->name,
            'date' => $r->created_at?->toDateString(),
        ];
    }

    // -- Inter-branch requests -------------------------------------------------

    public function requestForm(Request $request): Response
    {
        $kind = $request->query('kind') === 'Product' ? 'Product' : 'Fund';
        $branch = $this->activeBranch($request);

        return Inertia::render('Accounts/TransferRequest', [
            'kind' => $kind,
            'branches' => $this->branchOptions($request),
            'fromBranch' => ['id' => $branch->id, 'code' => $branch->code, 'name' => $branch->name],
            'businessDate' => $this->businessDays->currentDate($branch->id),
        ]);
    }

    public function storeRequest(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:Fund,Product'],
            'to_branch_id' => ['required', 'integer', 'exists:branches,id'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'item_name' => ['nullable', 'string', 'max:120'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'comments' => ['nullable', 'string', 'max:500'],
        ]);
        if ($data['kind'] === 'Fund' && empty($data['amount'])) {
            return back()->with('error', __('transfer_amount_required'));
        }
        if ($data['kind'] === 'Product' && empty($data['item_name'])) {
            return back()->with('error', __('transfer_item_required'));
        }
        $created = $this->transfers->createRequest($request->user(), $data + [
            'from_branch_id' => $this->activeBranch($request)->id,
        ]);

        return back()->with('success', __('saved').' — '.$created->request_no);
    }

    public function requestList(Request $request): Response
    {
        $branchIds = $this->branchAccess->accessibleIds($request->user());
        $view = (string) $request->query('view', 'all');
        $query = FundTransferRequest::with(['fromBranch:id,code,name', 'toBranch:id,code,name', 'requestedBy:id,name'])
            ->where(function ($q) use ($branchIds) {
                $q->whereIn('from_branch_id', $branchIds)->orWhereIn('to_branch_id', $branchIds);
            })->orderByDesc('id');
        if ($view === 'receive') {
            $query->whereIn('to_branch_id', $branchIds)->where('status', 'Approved');
        } elseif ($view === 'outgoing') {
            $query->whereIn('from_branch_id', $branchIds);
        } elseif ($view === 'incoming') {
            $query->whereIn('to_branch_id', $branchIds);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('kind')) {
            $query->where('kind', $request->query('kind'));
        }

        return Inertia::render('Accounts/TransferList', [
            'view' => $view,
            'rows' => $query->limit(200)->get()->map(fn (FundTransferRequest $r) => $this->requestRow($r))->all(),
            'accessibleBranchIds' => $branchIds,
            'filters' => $request->only(['status', 'kind']),
        ]);
    }

    public function approve(Request $request, FundTransferRequest $transfer): RedirectResponse
    {
        try {
            $this->transfers->approveSend($transfer, $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('saved'));
    }

    public function receive(Request $request, FundTransferRequest $transfer): RedirectResponse
    {
        try {
            $this->transfers->receive($transfer, $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('saved'));
    }

    public function close(Request $request, FundTransferRequest $transfer): RedirectResponse
    {
        $data = $request->validate(['to' => ['required', 'in:Rejected,Cancelled']]);
        try {
            $this->transfers->closeRequest($transfer, $data['to']);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('saved'));
    }

    /** Branch Wise Balance: clearing position + cash/bank per branch. */
    public function branchBalances(Request $request): Response
    {
        $branches = $this->branchAccess->visibleBranches($request->user());
        $rows = [];
        foreach ($branches as $branch) {
            $clearing = $this->posting->ledgerBalance(
                \App\Models\Ledger::where('ledger_code', AccountsService::INTER_BRANCH_CLEARING)->firstOrFail(), $branch->id
            );
            $cash = $this->posting->ledgerBalance(
                \App\Models\Ledger::where('ledger_code', AccountsService::CASH_IN_HAND)->firstOrFail(), $branch->id
            );
            $bank = $this->posting->ledgerBalance(
                \App\Models\Ledger::where('ledger_code', AccountsService::CASH_AT_BANK)->firstOrFail(), $branch->id
            );
            $rows[] = ['branch' => $branch->code.' — '.$branch->name, 'clearing' => $clearing, 'cash' => $cash, 'bank' => $bank];
        }

        return Inertia::render('Accounts/BranchBalances', ['rows' => $rows]);
    }

    /** Branch Transaction Statement: clearing-ledger statement per branch pair activity. */
    public function branchStatement(Request $request): Response
    {
        $branch = $this->activeBranch($request);
        if ($request->filled('branch_id')) {
            abort_unless($this->branchAccess->canAccess($request->user(), (int) $request->query('branch_id')), 403);
            $branch = Branch::findOrFail((int) $request->query('branch_id'));
        }
        $businessDate = $this->businessDays->currentDate($branch->id);
        $from = (string) $request->query('from', $this->reports->fiscalYearStart($branch->id, $businessDate));
        $to = (string) $request->query('to', $businessDate);
        $clearing = \App\Models\Ledger::where('ledger_code', AccountsService::INTER_BRANCH_CLEARING)->firstOrFail();
        $statement = $this->reports->ledgerStatement([$branch->id], $clearing, $from, $to);

        return Inertia::render('Accounts/BranchStatement', [
            'statement' => $statement, 'from' => $from, 'to' => $to,
            'branchId' => $branch->id, 'branches' => $this->branchOptions($request),
            'requests' => FundTransferRequest::with(['fromBranch:id,code', 'toBranch:id,code'])
                ->where(fn ($q) => $q->where('from_branch_id', $branch->id)->orWhere('to_branch_id', $branch->id))
                ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
                ->orderByDesc('id')->limit(100)->get()->map(fn (FundTransferRequest $r) => $this->requestRow($r))->all(),
        ]);
    }

    // -- Cash management ---------------------------------------------------------

    private function branchUsers(Request $request, int $branchId)
    {
        return User::whereHas('branches', fn ($q) => $q->where('branches.id', $branchId))
            ->orWhereHas('roles', fn ($q) => $q->where('name', 'super-admin'))
            ->orderBy('name')->get(['id', 'name', 'username'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'username' => $u->username])->all();
    }

    public function cashForm(Request $request): Response
    {
        $branch = $this->activeBranch($request);
        $businessDate = $this->businessDays->currentDate($branch->id);
        $pending = CashTransfer::with(['fromUser:id,name', 'toUser:id,name'])
            ->where('branch_id', $branch->id)->where('status', 'Pending')
            ->orderBy('id')->get()->map(fn (CashTransfer $t) => [
                'id' => $t->id, 'from_user' => $t->fromUser?->name, 'to_user' => $t->toUser?->name,
                'from_user_id' => $t->from_user_id, 'to_user_id' => $t->to_user_id,
                'amount' => (string) $t->amount, 'comments' => $t->comments,
                'date' => $t->business_date->toDateString(),
            ])->all();
        $counts = \App\Models\CashCount::with('user:id,name')
            ->where('branch_id', $branch->id)->where('business_date', $businessDate)
            ->get()->map(fn ($c) => [
                'user' => $c->user?->name, 'expected' => (string) $c->expected_balance,
                'counted' => (string) $c->counted_balance, 'difference' => (string) $c->difference,
            ])->all();

        return Inertia::render('Accounts/Cash', [
            'users' => $this->branchUsers($request, $branch->id),
            'pending' => $pending, 'counts' => $counts,
            'currentUserId' => $request->user()->id,
            'businessDate' => $businessDate,
            'branch' => ['id' => $branch->id, 'code' => $branch->code, 'name' => $branch->name],
        ]);
    }

    public function storeCashTransfer(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'from_user_id' => ['required', 'integer', 'exists:users,id'],
            'to_user_id' => ['required', 'integer', 'exists:users,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'comments' => ['nullable', 'string', 'max:500'],
        ]);
        try {
            $this->transfers->createCashTransfer(
                $request->user(), $this->activeBranch($request)->id,
                (int) $data['from_user_id'], (int) $data['to_user_id'],
                (string) $data['amount'], $data['comments'] ?? null,
            );
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('saved'));
    }

    public function receiveCash(Request $request, CashTransfer $transfer): RedirectResponse
    {
        abort_unless($transfer->branch_id === $this->activeBranch($request)->id
            || $this->branchAccess->canAccess($request->user(), $transfer->branch_id), 403);
        $data = $request->validate(['counted_amount' => ['required', 'numeric', 'min:0']]);
        try {
            $this->transfers->receiveCashTransfer($transfer, $request->user(), (string) $data['counted_amount']);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('saved'));
    }

    public function cancelCash(CashTransfer $transfer): RedirectResponse
    {
        try {
            $this->transfers->cancelCashTransfer($transfer);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('saved'));
    }

    public function storeCount(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'counted_balance' => ['required', 'numeric', 'min:0'],
        ]);
        $this->transfers->recordCashCount(
            $request->user(), $this->activeBranch($request)->id,
            (int) $data['user_id'], (string) $data['counted_balance'],
        );

        return back()->with('success', __('saved'));
    }

    /** Cashier Balance Report: every cash holder's sub-ledger position. */
    public function cashierBalances(Request $request): Response
    {
        $branchIds = $this->branchAccess->accessibleIds($request->user());
        $rows = CashLedger::with('user:id,name,username')
            ->whereIn('branch_id', $branchIds)->orderByDesc('current_balance')->get()
            ->map(fn (CashLedger $row) => [
                'user' => $row->user?->name, 'username' => $row->user?->username,
                'branch_id' => $row->branch_id, 'balance' => (string) $row->current_balance,
            ])->all();

        return Inertia::render('Accounts/CashierBalances', ['rows' => $rows]);
    }

    /** Cashier Transaction Details = cash sub-ledger rows (report page). */
    public function cashierDetails(Request $request): Response
    {
        $branch = $this->activeBranch($request);
        $businessDate = $this->businessDays->currentDate($branch->id);
        $from = (string) $request->query('from', $businessDate);
        $to = (string) $request->query('to', $businessDate);
        $userId = $request->filled('user_id') ? (int) $request->query('user_id') : null;
        $branchIds = $request->query('scope') === 'all'
            ? $this->branchAccess->accessibleIds($request->user())
            : [$branch->id];

        return Inertia::render('Accounts/CashierDetails', [
            'rows' => $this->reports->cashTransactionDetails($branchIds, $userId, $from, $to),
            'from' => $from, 'to' => $to, 'userId' => $userId,
            'users' => $this->branchUsers($request, $branch->id),
        ]);
    }
}
