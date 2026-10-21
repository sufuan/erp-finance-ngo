<?php

namespace App\Http\Controllers\Deposits;

use App\Http\Controllers\Controller;
use App\Models\DepositAccount;
use App\Models\DepositTransaction;
use App\Models\DepositTransfer;
use App\Models\Member;
use App\Services\AuditService;
use App\Services\BusinessDayService;
use App\Services\DepositTransactionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Step 11 — deposit receive / payment / transfer / share pages. */
class DepositTransactionController extends Controller
{
    public function __construct(private DepositTransactionService $service) {}

    private function findMember(Request $request, string $param = 'member'): ?Member
    {
        $needle = trim((string) $request->query($param, ''));
        if ($needle === '') {
            return null;
        }

        return Member::with(['samity'])->where('member_no', $needle)
            ->orWhere('id', ctype_digit($needle) ? (int) $needle : -1)->first();
    }

    private function memberAccounts(?Member $member, ?string $productCode = null)
    {
        if (! $member) {
            return [];
        }
        $query = DepositAccount::with('product:id,code,name_en')
            ->where('member_id', $member->id)->where('is_active', true)->whereNull('closing_date');
        if ($productCode) {
            $query->whereHas('product', fn ($q) => $q->where('code', $productCode));
        }

        return $query->orderBy('id')->get()->map(fn (DepositAccount $a) => [
            'id' => $a->id, 'account_number' => $a->account_number,
            'product' => $a->product?->code, 'product_name' => $a->product?->name_en,
            'current_balance' => (string) $a->current_balance,
            'opening_date' => $a->opening_date?->toDateString(),
        ])->all();
    }

    private function snapshot(?Member $member, ?DepositAccount $account): ?array
    {
        if (! $member) {
            return null;
        }

        return [
            'member_no' => $member->member_no, 'name' => $member->name,
            'samity' => $member->samity?->name, 'mobile' => $member->mobile,
            'share_amount' => (string) $member->share_amount,
            'account' => $account ? [
                'account_number' => $account->account_number,
                'product' => $account->product?->code,
                'current_balance' => (string) $account->current_balance,
                'total_credit' => (string) $account->total_credit,
                'total_debit' => (string) $account->total_debit,
            ] : null,
        ];
    }

    private function historyFor(Request $request, ?Member $member): array
    {
        $accountId = $request->integer('account_id');
        if (! $member || ! $accountId) {
            return [];
        }

        return DepositTransaction::where('account_id', $accountId)->orderByDesc('id')->limit(20)->get()
            ->map(fn (DepositTransaction $d) => [
                'id' => $d->id, 'tran_type' => $d->tran_type, 'amount' => (string) $d->amount,
                'tran_date' => $d->tran_date?->toDateString(), 'document_number' => $d->document_number,
                'comments' => $d->comments, 'cancel_at' => $d->cancel_at?->toDateTimeString(),
            ])->all();
    }

    private function moveProps(Request $request, ?string $productCode): array
    {
        $member = $this->findMember($request);
        $accounts = $this->memberAccounts($member, $productCode);
        $account = null;
        if ($request->filled('account_id')) {
            $account = collect($accounts)->firstWhere('id', $request->integer('account_id'));
            $account = $account ? DepositAccount::with('product')->find($account['id']) : null;
        }

        return [
            'member' => $member ? ['id' => $member->id, 'member_no' => $member->member_no] : null,
            'memberQuery' => (string) $request->query('member', ''),
            'accounts' => $accounts,
            'snapshot' => $this->snapshot($member, $account),
            'history' => $this->historyFor($request, $member),
            'accountId' => $request->integer('account_id') ?: null,
            'businessDate' => app(BusinessDayService::class)->currentDate(
                $member?->branch_id ?? (int) \App\Models\Branch::where('type', 'Head Office')->value('id')
            ),
        ];
    }

    public function receiveForm(Request $request): Response
    {
        return Inertia::render('Deposits/Receive', [
            ...$this->moveProps($request, $request->query('product')),
            'preset' => $request->query('product'),
        ]);
    }

    public function storeReceive(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer', 'exists:deposit_accounts,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'document_number' => ['nullable', 'string', 'max:60'],
            'comments' => ['nullable', 'string'],
        ]);
        $account = DepositAccount::findOrFail($data['account_id']);
        $detail = $this->service->receive($account, (string) $data['amount'], $data['document_number'] ?? null, $data['comments'] ?? null, $request->user());
        $audit->log($request->user()->id, 'deposit.received', 'deposit_transactions', $detail->id, null, ['amount' => $detail->amount]);

        return back()->with('success', __('saved'));
    }

    public function paymentForm(Request $request): Response
    {
        return Inertia::render('Deposits/Payment', $this->moveProps($request, $request->query('product')));
    }

    public function storePayment(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer', 'exists:deposit_accounts,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'document_number' => ['nullable', 'string', 'max:60'],
            'comments' => ['nullable', 'string'],
        ]);
        $account = DepositAccount::findOrFail($data['account_id']);
        $detail = $this->service->payment($account, (string) $data['amount'], $data['document_number'] ?? null, $data['comments'] ?? null, $request->user());
        $audit->log($request->user()->id, 'deposit.paid', 'deposit_transactions', $detail->id, null, ['amount' => $detail->amount]);

        return back()->with('success', __('saved'));
    }

    public function cancelTransaction(Request $request, DepositTransaction $transaction): RedirectResponse
    {
        abort_if($transaction->cancel_at !== null, 422);
        $this->service->cancelTransaction($transaction, $request->user(), (string) $request->input('reason', 'Cancelled from deposit screen'));

        return back()->with('success', __('saved'));
    }

    public function transferForm(Request $request): Response
    {
        $fromMember = $this->findMember($request, 'from_member');
        $toMember = $this->findMember($request, 'to_member');
        $transfers = DepositTransfer::with([
            'fromAccount:id,account_number', 'toAccount:id,account_number',
        ])->orderByDesc('id')->limit(50)->get()->map(fn (DepositTransfer $tr) => [
            'id' => $tr->id, 'from_account' => $tr->fromAccount?->account_number,
            'to_account' => $tr->toAccount?->account_number, 'amount' => (string) $tr->amount,
            'transfer_date' => $tr->transfer_date?->toDateString(),
            'document_number' => $tr->document_number, 'comments' => $tr->comments,
            'cancel_at' => $tr->cancel_at?->toDateTimeString(),
            'cancel_by' => $tr->cancel_by,
        ])->all();

        return Inertia::render('Deposits/Transfer', [
            'fromMember' => $fromMember ? ['id' => $fromMember->id, 'member_no' => $fromMember->member_no, 'name' => $fromMember->name] : null,
            'toMember' => $toMember ? ['id' => $toMember->id, 'member_no' => $toMember->member_no, 'name' => $toMember->name] : null,
            'fromQuery' => (string) $request->query('from_member', ''),
            'toQuery' => (string) $request->query('to_member', ''),
            'fromAccounts' => $this->memberAccounts($fromMember),
            'toAccounts' => $this->memberAccounts($toMember),
            'transfers' => $transfers,
            'businessDate' => app(BusinessDayService::class)->currentDate(
                $fromMember?->branch_id ?? (int) \App\Models\Branch::where('type', 'Head Office')->value('id')
            ),
        ]);
    }

    public function storeTransfer(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'from_account_id' => ['required', 'integer', 'exists:deposit_accounts,id'],
            'to_account_id' => ['required', 'integer', 'exists:deposit_accounts,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'document_number' => ['nullable', 'string', 'max:60'],
            'comments' => ['required', 'string'],
        ]);
        $transfer = $this->service->transfer(
            DepositAccount::findOrFail($data['from_account_id']),
            DepositAccount::findOrFail($data['to_account_id']),
            (string) $data['amount'], $data['document_number'] ?? null, $data['comments'], $request->user(),
        );
        $audit->log($request->user()->id, 'deposit.transferred', 'deposit_transfers', $transfer->id, null, ['amount' => $transfer->amount]);

        return back()->with('success', __('saved'));
    }

    public function cancelTransfer(Request $request, DepositTransfer $transfer): RedirectResponse
    {
        abort_if($transfer->cancel_at !== null, 422);
        $this->service->cancelTransfer($transfer, $request->user(), (string) $request->input('reason', 'Cancelled from deposit screen'));

        return back()->with('success', __('saved'));
    }

    public function shareForm(Request $request): Response
    {
        $member = $this->findMember($request);

        return Inertia::render('Deposits/Share', [
            'member' => $member ? ['id' => $member->id, 'member_no' => $member->member_no, 'name' => $member->name] : null,
            'memberQuery' => (string) $request->query('member', ''),
            'snapshot' => $this->snapshot($member, null),
            'mode' => $request->query('mode') === 'payment' ? 'payment' : 'receive',
            'businessDate' => app(BusinessDayService::class)->currentDate(
                $member?->branch_id ?? (int) \App\Models\Branch::where('type', 'Head Office')->value('id')
            ),
        ]);
    }

    public function storeShare(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'member_id' => ['required', 'integer', 'exists:members,id'],
            'mode' => ['required', 'in:receive,payment'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'document_number' => ['nullable', 'string', 'max:60'],
            'comments' => ['nullable', 'string'],
        ]);
        $member = Member::findOrFail($data['member_id']);
        $voucher = $data['mode'] === 'receive'
            ? $this->service->shareReceive($member, (string) $data['amount'], $data['document_number'] ?? null, $data['comments'] ?? null, $request->user())
            : $this->service->sharePayment($member, (string) $data['amount'], $data['document_number'] ?? null, $data['comments'] ?? null, $request->user());
        $audit->log($request->user()->id, 'share.'.$data['mode'], 'members', $member->id, null, ['amount' => $data['amount'], 'voucher_id' => $voucher->id]);

        return back()->with('success', __('saved'));
    }
}
