<?php

namespace App\Http\Controllers\Deposits;

use App\Http\Controllers\Controller;
use App\Models\DepositAccount;
use App\Models\DepositBlock;
use App\Models\DepositClosing;
use App\Models\DepositProduct;
use App\Models\Member;
use App\Services\AuditService;
use App\Services\BusinessDayService;
use App\Services\DepositClosingService;
use App\Services\DepositTransactionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Step 12 — transaction blocks, block clearing, and deposit closing. */
class DepositBlockClosingController extends Controller
{
    public function __construct(
        private DepositClosingService $closings,
        private DepositTransactionService $transactions,
    ) {}

    private function findMember(Request $request): ?Member
    {
        $needle = trim((string) $request->query('member', ''));
        if ($needle === '') {
            return null;
        }

        return Member::with('samity')->where('member_no', $needle)
            ->orWhere('id', ctype_digit($needle) ? (int) $needle : -1)->first();
    }

    private function memberAccounts(?Member $member, bool $onlyOpen = false)
    {
        if (! $member) {
            return [];
        }
        $query = DepositAccount::with('product:id,code')->where('member_id', $member->id);
        if ($onlyOpen) {
            $query->where('is_active', true)->whereNull('closing_date');
        }

        return $query->orderBy('id')->get()->map(fn (DepositAccount $a) => [
            'id' => $a->id, 'account_number' => $a->account_number, 'product' => $a->product?->code,
            'current_balance' => (string) $a->current_balance, 'is_active' => $a->is_active,
        ])->all();
    }

    private function blockRow(DepositBlock $b): array
    {
        $b->loadMissing(['account.member', 'account.branch']);

        return [
            'id' => $b->id, 'branch_code' => $b->account?->branch?->code,
            'member' => $b->account?->member ? $b->account->member->member_no.' — '.$b->account->member->name : null,
            'account_number' => $b->account?->account_number,
            'block_date' => $b->block_effective_date?->toDateString(),
            'block_amount' => (string) $b->block_amount, 'reason' => $b->reason,
            'deposit_block' => $b->deposit_block, 'withdrawal_block' => $b->withdrawal_block,
            'block_by' => $b->block_by, 'block_at' => $b->block_at?->toDateTimeString(),
            'clear_date' => $b->clear_date?->toDateString(),
            'clear_amount' => $b->clear_amount !== null ? (string) $b->clear_amount : null,
            'clear_reason' => $b->clear_reason, 'cleared_by' => $b->cleared_by,
        ];
    }

    public function blocksForm(Request $request): Response
    {
        $member = $this->findMember($request);
        $blocks = DepositBlock::with(['account.member', 'account.branch'])->orderByDesc('id');
        if ($member) {
            $blocks->whereHas('account', fn ($q) => $q->where('member_id', $member->id));
        }
        $account = $request->filled('account_id')
            ? DepositAccount::find($request->integer('account_id')) : null;

        return Inertia::render('Deposits/Blocks', [
            'mode' => 'add',
            'member' => $member ? ['id' => $member->id, 'member_no' => $member->member_no, 'name' => $member->name] : null,
            'memberQuery' => (string) $request->query('member', ''),
            'accounts' => $this->memberAccounts($member, true),
            'accountId' => $account?->id,
            'currentBlock' => $account ? $this->transactions->withdrawalBlockTotal($account) : null,
            'accountBalance' => $account ? (string) $account->current_balance : null,
            'blocks' => $blocks->limit(50)->get()->map(fn (DepositBlock $b) => $this->blockRow($b))->all(),
            'businessDate' => $this->businessDate($member),
        ]);
    }

    public function storeBlock(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer', 'exists:deposit_accounts,id'],
            'block_effective_date' => ['required', 'date'],
            'block_amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string'],
            'deposit_block' => ['nullable', 'boolean'],
            'withdrawal_block' => ['nullable', 'boolean'],
        ]);
        if (empty($data['deposit_block']) && empty($data['withdrawal_block'])) {
            throw ValidationException::withMessages(['withdrawal_block' => __('select_block_type')]);
        }
        $block = DepositBlock::create([
            ...$data,
            'deposit_block' => (bool) ($data['deposit_block'] ?? false),
            'withdrawal_block' => (bool) ($data['withdrawal_block'] ?? false),
            'block_by' => $request->user()->id, 'block_at' => now(),
        ]);
        $audit->log($request->user()->id, 'deposit.blocked', 'deposit_blocks', $block->id, null, ['amount' => $block->block_amount]);

        return back()->with('success', __('saved'));
    }

    public function clearForm(Request $request): Response
    {
        $member = $this->findMember($request);
        $blocks = DepositBlock::with(['account.member', 'account.branch'])->whereNull('clear_date')->orderByDesc('id');
        if ($member) {
            $blocks->whereHas('account', fn ($q) => $q->where('member_id', $member->id));
        }
        $account = $request->filled('account_id')
            ? DepositAccount::find($request->integer('account_id')) : null;

        return Inertia::render('Deposits/Blocks', [
            'mode' => 'clear',
            'member' => $member ? ['id' => $member->id, 'member_no' => $member->member_no, 'name' => $member->name] : null,
            'memberQuery' => (string) $request->query('member', ''),
            'accounts' => $this->memberAccounts($member),
            'accountId' => $account?->id,
            'currentBlock' => $account ? $this->transactions->withdrawalBlockTotal($account) : null,
            'accountBalance' => $account ? (string) $account->current_balance : null,
            'blocks' => $blocks->limit(50)->get()->map(fn (DepositBlock $b) => $this->blockRow($b))->all(),
            'businessDate' => $this->businessDate($member),
        ]);
    }

    public function clearBlock(Request $request, DepositBlock $block, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'clear_date' => ['required', 'date'],
            'clear_amount' => ['required', 'numeric', 'gt:0'],
            'clear_reason' => ['required', 'string'],
            'deposit_block' => ['nullable', 'boolean'],
            'withdrawal_block' => ['nullable', 'boolean'],
        ]);
        abort_if($block->clear_date !== null, 422);
        if (\Brick\Math\BigDecimal::of((string) $data['clear_amount'])->isGreaterThan(\Brick\Math\BigDecimal::of((string) $block->block_amount))) {
            throw ValidationException::withMessages(['clear_amount' => __('clear_exceeds_block')]);
        }
        $block->update([
            'clear_date' => $data['clear_date'], 'clear_amount' => $data['clear_amount'],
            'clear_reason' => $data['clear_reason'],
            'cleared_by' => $request->user()->id, 'cleared_at' => now(),
        ]);
        $audit->log($request->user()->id, 'deposit.block_cleared', 'deposit_blocks', $block->id, null, ['amount' => $block->clear_amount]);

        return back()->with('success', __('saved'));
    }

    public function closingForm(Request $request): Response
    {
        $member = $this->findMember($request);
        $account = $request->filled('account_id')
            ? DepositAccount::with('product')->find($request->integer('account_id')) : null;
        $closings = DepositClosing::with(['account.member', 'account.branch'])->orderByDesc('id')->limit(50)->get()
            ->map(fn (DepositClosing $c) => [
                'id' => $c->id, 'branch_code' => $c->account?->branch?->code,
                'member' => $c->account?->member ? $c->account->member->member_no.' — '.$c->account->member->name : null,
                'account_number' => $c->account?->account_number,
                'closing_date' => $c->closing_date?->toDateString(),
                'balance' => (string) $c->balance, 'profit_payment' => (string) $c->profit_payment,
                'closing_charge' => (string) $c->closing_charge, 'payable_amount' => (string) $c->payable_amount,
                'reason' => $c->reason, 'closed_by' => $c->closed_by,
                'closed_at' => $c->closed_at?->toDateTimeString(),
                'cancelled_by' => $c->cancelled_by, 'cancelled_at' => $c->cancelled_at?->toDateTimeString(),
            ])->all();

        return Inertia::render('Deposits/Closing', [
            'member' => $member ? ['id' => $member->id, 'member_no' => $member->member_no, 'name' => $member->name] : null,
            'memberQuery' => (string) $request->query('member', ''),
            'accounts' => $this->memberAccounts($member),
            'accountId' => $account?->id,
            'preview' => $account ? $this->closings->preview($account, $this->businessDate($member)) : null,
            'closings' => $closings,
            'businessDate' => $this->businessDate($member),
            'products' => DepositProduct::where('is_active', true)->count(),
        ]);
    }

    public function storeClosing(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer', 'exists:deposit_accounts,id'],
            'closing_date' => ['required', 'date'],
            'reason' => ['nullable', 'string'],
            'profit_payment' => ['nullable', 'numeric', 'min:0'],
        ]);
        $closing = $this->closings->close(
            DepositAccount::findOrFail($data['account_id']),
            $data['closing_date'], $data['reason'] ?? null, $request->user(),
            (string) ($data['profit_payment'] ?? '0'),
        );
        $audit->log($request->user()->id, 'deposit.closed', 'deposit_closings', $closing->id, null, ['payable' => $closing->payable_amount]);

        return back()->with('success', __('saved'));
    }

    public function cancelClosing(Request $request, DepositClosing $closing, AuditService $audit): RedirectResponse
    {
        $this->closings->cancelClosing($closing, $request->user());
        $audit->log($request->user()->id, 'deposit.closing_cancelled', 'deposit_closings', $closing->id, null, []);

        return back()->with('success', __('saved'));
    }

    private function businessDate(?Member $member): string
    {
        $branchId = $member?->branch_id ?? (int) \App\Models\Branch::where('type', 'Head Office')->value('id');

        return app(BusinessDayService::class)->currentDate($branchId);
    }
}
