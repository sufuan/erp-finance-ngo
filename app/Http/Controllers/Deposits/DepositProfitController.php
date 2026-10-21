<?php

namespace App\Http\Controllers\Deposits;

use App\Http\Controllers\Controller;
use App\Models\DepositAccount;
use App\Models\DepositProduct;
use App\Models\Member;
use App\Models\ProfitDistribution;
use App\Models\ProfitSetup;
use App\Models\Samity;
use App\Models\TransactionProfile;
use App\Services\AuditService;
use App\Services\BusinessDayService;
use App\Services\ProfitDistributionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Step 13 — profit setup, distribution (manual + automatic), payment, installment profiles. */
class DepositProfitController extends Controller
{
    public function __construct(private ProfitDistributionService $service) {}

    private function findMember(Request $request): ?Member
    {
        $needle = trim((string) $request->query('member', ''));
        if ($needle === '') {
            return null;
        }

        return Member::with('samity')->where('member_no', $needle)
            ->orWhere('id', ctype_digit($needle) ? (int) $needle : -1)->first();
    }

    private function memberAccounts(?Member $member): array
    {
        if (! $member) {
            return [];
        }

        return DepositAccount::with('product:id,code')->where('member_id', $member->id)
            ->orderBy('id')->get()->map(fn (DepositAccount $a) => [
                'id' => $a->id, 'account_number' => $a->account_number, 'product' => $a->product?->code,
                'current_balance' => (string) $a->current_balance, 'profit_rate' => (string) $a->profit_rate,
                'installment_amount' => $a->installment_amount !== null ? (string) $a->installment_amount : null,
                'is_active' => $a->is_active,
            ])->all();
    }

    private function memberPayload(?Member $member): ?array
    {
        return $member ? ['id' => $member->id, 'member_no' => $member->member_no, 'name' => $member->name] : null;
    }

    // ---- Account profit setup ----

    public function setupForm(Request $request): Response
    {
        $member = $this->findMember($request);
        $setups = ProfitSetup::with(['account.member', 'account.product'])->orderByDesc('id')->limit(50)->get()
            ->map(fn (ProfitSetup $s) => [
                'id' => $s->id, 'account_number' => $s->account?->account_number,
                'member' => $s->account?->member ? $s->account->member->member_no.' — '.$s->account->member->name : null,
                'effective_date' => $s->effective_date?->toDateString(), 'serial' => $s->serial,
                'profit_rate' => (string) $s->profit_rate, 'setup_by' => $s->setup_by,
                'setup_at' => $s->setup_at?->toDateTimeString(),
                'cancelled_by' => $s->cancelled_by, 'cancelled_at' => $s->cancelled_at?->toDateTimeString(),
            ])->all();

        return Inertia::render('Deposits/ProfitSetup', [
            'member' => $this->memberPayload($member),
            'memberQuery' => (string) $request->query('member', ''),
            'accounts' => $this->memberAccounts($member),
            'accountId' => $request->integer('account_id') ?: null,
            'setups' => $setups,
            'businessDate' => $this->businessDate($member),
        ]);
    }

    public function storeSetup(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer', 'exists:deposit_accounts,id'],
            'effective_date' => ['required', 'date'],
            'profit_rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);
        $serial = ProfitSetup::where('deposit_account_id', $data['account_id'])->max('serial') + 1;
        $setup = ProfitSetup::create([
            'deposit_account_id' => $data['account_id'], 'effective_date' => $data['effective_date'],
            'serial' => $serial, 'profit_rate' => $data['profit_rate'],
            'setup_by' => $request->user()->id, 'setup_at' => now(),
        ]);
        $audit->log($request->user()->id, 'deposit.profit_setup', 'profit_setups', $setup->id, null, ['rate' => $setup->profit_rate]);

        return back()->with('success', __('saved'));
    }

    public function cancelSetup(Request $request, ProfitSetup $setup, AuditService $audit): RedirectResponse
    {
        $setup->update(['cancelled_by' => $request->user()->id, 'cancelled_at' => now()]);
        $audit->log($request->user()->id, 'deposit.profit_setup_cancelled', 'profit_setups', $setup->id, null, []);

        return back()->with('success', __('saved'));
    }

    // ---- Distribution (manual + automatic share the calculate endpoint) ----

    public function distributionForm(Request $request): Response
    {
        return Inertia::render('Deposits/ProfitDistribution', [
            'auto' => false,
            'samities' => Samity::where('status', 'Active')->orderBy('name')->get(['id', 'name', 'samity_code'])->all(),
            'products' => DepositProduct::where('is_active', true)->orderBy('code')->get(['id', 'code'])->all(),
            'businessDate' => $this->businessDate(null),
        ]);
    }

    public function autoForm(Request $request): Response
    {
        return Inertia::render('Deposits/ProfitDistribution', [
            'auto' => true,
            'samities' => Samity::where('status', 'Active')->orderBy('name')->get(['id', 'name', 'samity_code'])->all(),
            'products' => DepositProduct::where('is_active', true)->orderBy('code')->get(['id', 'code'])->all(),
            'businessDate' => $this->businessDate(null),
        ]);
    }

    public function calculate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'samity_id' => ['required', 'integer', 'exists:samities,id'],
            'account_type' => ['required', 'string'],
            'profit_basis' => ['required', 'in:D,B,M,X,A,O,N'],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'profit_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'posting_date' => ['required', 'date'],
            'exclude_zero_balance' => ['nullable', 'boolean'],
            'auto_rates' => ['nullable', 'boolean'],
        ]);
        $distribution = $this->service->calculate([
            'samity_id' => $data['samity_id'], 'account_type' => $data['account_type'],
            'profit_basis' => $data['profit_basis'], 'from_date' => $data['from_date'],
            'to_date' => $data['to_date'], 'profit_rate' => (string) ($data['profit_rate'] ?? '0'),
            'posting_date' => $data['posting_date'],
            'exclude_zero_balance' => (bool) ($data['exclude_zero_balance'] ?? true),
            'auto_rates' => (bool) ($data['auto_rates'] ?? false),
        ], $request->user());

        return redirect()->route('deposits.profit.distributions.show', $distribution);
    }

    public function distributionIndex(): Response
    {
        $rows = ProfitDistribution::with('samity')->orderByDesc('id')->limit(100)->get()
            ->map(fn (ProfitDistribution $d) => [
                'id' => $d->id, 'samity' => $d->samity?->name, 'account_type' => $d->account_type,
                'from_date' => $d->from_date?->toDateString(), 'to_date' => $d->to_date?->toDateString(),
                'profit_basis' => $d->profit_basis, 'profit_rate' => (string) $d->profit_rate,
                'distribution_amount' => (string) $d->distribution_amount, 'status' => $d->status,
                'posted_by' => $d->posted_by, 'posted_at' => $d->posted_at?->toDateTimeString(),
            ])->all();

        return Inertia::render('Deposits/ProfitDistributions', ['distributions' => $rows]);
    }

    public function distributionShow(ProfitDistribution $distribution): Response
    {
        $distribution->load(['samity', 'branch', 'lines.account.member', 'lines.account.product']);
        $lines = $distribution->lines->map(fn ($l) => [
            'id' => $l->id,
            'member' => $l->account?->member ? $l->account->member->member_no.' — '.$l->account->member->name : null,
            'center' => $distribution->samity?->samity_code.' — '.$distribution->samity?->name,
            'account_number' => $l->account?->account_number,
            'balance' => (string) $l->balance, 'profit_on_amount' => (string) $l->profit_on_amount,
            'profit_pending' => (string) $l->profit_pending, 'total_payable' => (string) $l->total_payable,
        ])->all();

        return Inertia::render('Deposits/ProfitDistributionShow', [
            'distribution' => [
                'id' => $distribution->id, 'samity' => $distribution->samity?->name,
                'account_type' => $distribution->account_type,
                'from_date' => $distribution->from_date?->toDateString(),
                'to_date' => $distribution->to_date?->toDateString(),
                'profit_basis' => $distribution->profit_basis,
                'profit_rate' => (string) $distribution->profit_rate,
                'distribution_amount' => (string) $distribution->distribution_amount,
                'posting_date' => $distribution->posting_date?->toDateString(),
                'exclude_zero_balance' => $distribution->exclude_zero_balance,
                'status' => $distribution->status,
            ],
            'lines' => $lines,
        ]);
    }

    public function postDistribution(Request $request, ProfitDistribution $distribution): RedirectResponse
    {
        $this->service->post($distribution, $request->user());

        return back()->with('success', __('saved'));
    }

    // ---- Profit payment ----

    public function paymentForm(Request $request): Response
    {
        $member = $this->findMember($request);
        $account = $request->filled('account_id') ? DepositAccount::find($request->integer('account_id')) : null;

        return Inertia::render('Deposits/ProfitPayment', [
            'member' => $this->memberPayload($member),
            'memberQuery' => (string) $request->query('member', ''),
            'accounts' => $this->memberAccounts($member),
            'accountId' => $account?->id,
            'pending' => $account ? $this->service->pendingFor($account) : null,
            'paid' => $account ? $this->service->paidFor($account) : null,
            'accountRate' => $account ? (string) $account->profit_rate : null,
            'accountBalance' => $account ? (string) $account->current_balance : null,
            'businessDate' => $this->businessDate($member),
        ]);
    }

    public function storePayment(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer', 'exists:deposit_accounts,id'],
            'profit_amount' => ['required', 'numeric', 'gt:0'],
            'payment_date' => ['required', 'date'],
            'comments' => ['nullable', 'string'],
        ]);
        $payment = $this->service->payProfit(
            DepositAccount::findOrFail($data['account_id']),
            (string) $data['profit_amount'], $data['payment_date'],
            $data['comments'] ?? null, $request->user(),
        );
        $audit->log($request->user()->id, 'deposit.profit_paid', 'profit_payments', $payment->id, null, ['amount' => $payment->profit_amount]);

        return back()->with('success', __('saved'));
    }

    // ---- Installment / transaction profile ----

    public function profileForm(Request $request): Response
    {
        $member = $this->findMember($request);
        $profiles = TransactionProfile::with(['account.member'])->orderByDesc('id')->limit(50)->get()
            ->map(fn (TransactionProfile $p) => [
                'id' => $p->id, 'account_number' => $p->account?->account_number,
                'member' => $p->account?->member ? $p->account->member->member_no.' — '.$p->account->member->name : null,
                'installment_amount' => (string) $p->installment_amount,
                'from_date' => $p->from_date?->toDateString(), 'to_date' => $p->to_date?->toDateString(),
                'comments' => $p->comments,
            ])->all();

        return Inertia::render('Deposits/TransactionProfile', [
            'member' => $this->memberPayload($member),
            'memberQuery' => (string) $request->query('member', ''),
            'accounts' => $this->memberAccounts($member),
            'accountId' => $request->integer('account_id') ?: null,
            'profiles' => $profiles,
        ]);
    }

    public function storeProfile(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer', 'exists:deposit_accounts,id'],
            'installment_amount' => ['required', 'numeric', 'gt:0'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'comments' => ['nullable', 'string'],
        ]);
        $profile = TransactionProfile::create([
            'deposit_account_id' => $data['account_id'],
            'installment_amount' => $data['installment_amount'],
            'from_date' => $data['from_date'] ?? null, 'to_date' => $data['to_date'] ?? null,
            'comments' => $data['comments'] ?? null,
        ]);
        DepositAccount::where('id', $data['account_id'])->update(['installment_amount' => $data['installment_amount']]);
        $audit->log($request->user()->id, 'deposit.profile_updated', 'transaction_profiles', $profile->id, null, []);

        return back()->with('success', __('saved'));
    }

    private function businessDate(?Member $member): string
    {
        $branchId = $member?->branch_id ?? (int) \App\Models\Branch::where('type', 'Head Office')->value('id');

        return app(BusinessDayService::class)->currentDate($branchId);
    }
}
