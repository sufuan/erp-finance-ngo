<?php

namespace App\Http\Controllers\Deposits;

use App\Http\Controllers\Controller;
use App\Models\DepositAccount;
use App\Models\DepositProduct;
use App\Models\Member;
use App\Models\Nominee;
use App\Models\OtherAccount;
use App\Services\AuditService;
use App\Services\BranchAccessService;
use App\Services\DepositAccountService;
use App\Services\NumberingService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Step 10 — deposit account opening, list, and other-account opening. */
class DepositAccountController extends Controller
{
    private function businessDate(Request $request): string
    {
        $date = $request->attributes->get('business_date');

        return $date ? Carbon::parse($date)->toDateString() : now()->toDateString();
    }

    private function productOptions()
    {
        return DepositProduct::where('is_active', true)->orderBy('code')->get()
            ->map(fn (DepositProduct $p) => [
                'id' => $p->id, 'code' => $p->code, 'name_en' => $p->name_en, 'name_bn' => $p->name_bn,
                'default_profit_rate' => (string) $p->default_profit_rate,
                'maturity_months' => $p->maturity_months,
                'minimum_opening_amount' => (string) $p->minimum_opening_amount,
                'config' => $p->config,
            ])->all();
    }

    public function create(Request $request): Response
    {
        $member = null;
        $needle = trim((string) $request->query('member', ''));
        if ($needle !== '') {
            $found = Member::with(['samity', 'branch', 'nominees'])
                ->where('member_no', $needle)
                ->orWhere('id', ctype_digit($needle) ? (int) $needle : -1)
                ->first();
            if ($found) {
                $member = [
                    'id' => $found->id, 'member_no' => $found->member_no, 'name' => $found->name,
                    'mobile' => $found->mobile, 'samity' => $found->samity?->name,
                    'branch' => $found->branch ? $found->branch->code.' — '.$found->branch->name : null,
                    'present_address' => $found->present_address,
                    'share_amount' => (string) $found->share_amount,
                    'photo_url' => $found->profile_photo ? '/files/'.$found->profile_photo : null,
                    'nominee' => $found->nominees->first() ? [
                        'id' => $found->nominees->first()->id, 'name' => $found->nominees->first()->name,
                    ] : null,
                ];
            }
        }

        return Inertia::render('Deposits/Opening', [
            'products' => $this->productOptions(),
            'member' => $member,
            'memberQuery' => $needle,
            'businessDate' => $this->businessDate($request),
        ]);
    }

    public function store(Request $request, DepositAccountService $service, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'member_id' => ['required', 'integer', 'exists:members,id'],
            'deposit_product_id' => ['required', 'integer', 'exists:deposit_products,id'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'title' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'phone' => ['required', 'string', 'max:30'],
            'opening_date' => ['required', 'date'],
            'profit_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'term_months' => ['nullable', 'integer', 'min:1', 'max:600'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'nominee_name' => ['nullable', 'string', 'max:255'],
            'nominee_dob' => ['nullable', 'date'],
            'nominee_phone' => ['nullable', 'string', 'max:30'],
            'nominee_relation' => ['nullable', 'string', 'max:50'],
            'nominee_address' => ['nullable', 'string'],
        ]);

        $member = Member::findOrFail($data['member_id']);
        $product = DepositProduct::findOrFail($data['deposit_product_id']);
        app(BranchAccessService::class)->visibleBranches($request->user())->pluck('id')
            ->contains($member->branch_id) ?: abort(403);

        // Account-level nominee override: a fresh nominee row for the member.
        $nomineeId = null;
        if (! empty($data['nominee_name'])) {
            $nomineeId = Nominee::create([
                'member_id' => $member->id,
                'name' => $data['nominee_name'],
                'dob' => $data['nominee_dob'] ?? null,
                'phone' => $data['nominee_phone'] ?? null,
                'relation' => $data['nominee_relation'] ?? null,
                'address' => $data['nominee_address'] ?? null,
            ])->id;
        }

        $account = $service->open($member, $product, [
            'title' => $data['title'] ?? null,
            'address' => $data['address'] ?? null,
            'phone' => $data['phone'],
            'opening_date' => $data['opening_date'],
            'profit_rate' => $data['profit_rate'] ?? null,
            'installment_amount' => $data['amount'] ?? null,
            'term_months' => $data['term_months'] ?? null,
            'credit_limit' => $data['credit_limit'] ?? 0,
            'nominee_id' => $nomineeId,
        ], $request->user()->id);

        $audit->log($request->user()->id, 'deposit_account.opened', 'deposit_accounts', $account->id, null, [
            'account_number' => $account->account_number, 'product' => $product->code, 'member_id' => $member->id,
        ]);

        return redirect()->route('deposits.index')->with('success', __('saved'));
    }

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'deposit_product_id' => ['nullable', 'integer'],
            'member_id' => ['nullable', 'string', 'max:30'],
            'from' => ['nullable', 'date'],
            'upto' => ['nullable', 'date'],
            'phone' => ['nullable', 'string', 'max:30'],
            'account' => ['nullable', 'string', 'max:30'],
        ]);

        $query = DepositAccount::with(['member:id,member_no,name', 'branch:id,code', 'product:id,code,name_en,name_bn'])
            ->orderByDesc('id');
        if (! empty($filters['deposit_product_id'])) {
            $query->where('deposit_product_id', $filters['deposit_product_id']);
        }
        if (! empty($filters['member_id'])) {
            $query->whereHas('member', fn ($q) => $q->where('member_no', $filters['member_id']));
        }
        if (! empty($filters['from'])) {
            $query->whereDate('opening_date', '>=', $filters['from']);
        }
        if (! empty($filters['upto'])) {
            $query->whereDate('opening_date', '<=', $filters['upto']);
        }
        if (! empty($filters['phone'])) {
            $query->where('phone', 'like', '%'.$filters['phone'].'%');
        }
        if (! empty($filters['account'])) {
            $query->where('account_number', 'like', '%'.$filters['account'].'%');
        }

        $accounts = $query->paginate(25)->withQueryString()->through(fn (DepositAccount $a) => [
            'id' => $a->id,
            'branch_code' => $a->branch?->code,
            'phone' => $a->phone,
            'member_no' => $a->member?->member_no,
            'product' => $a->product?->code,
            'account_number' => $a->account_number,
            'name' => $a->title,
            'address' => $a->address,
            'total_credit' => (string) $a->total_credit,
            'total_debit' => (string) $a->total_debit,
            'current_balance' => (string) $a->current_balance,
            'credit_limit' => (string) $a->credit_limit,
            'is_active' => $a->is_active,
            'opening_date' => $a->opening_date?->toDateString(),
            'closing_date' => $a->closing_date?->toDateString(),
        ]);

        return Inertia::render('Deposits/Index', [
            'accounts' => $accounts,
            'filters' => $filters,
            'products' => $this->productOptions(),
        ]);
    }

    public function otherCreate(Request $request): Response
    {
        return Inertia::render('Deposits/OtherOpening', [
            'accounts' => OtherAccount::orderByDesc('id')->limit(50)->get()->map(fn (OtherAccount $a) => [
                'id' => $a->id, 'account_type' => $a->account_type, 'name' => $a->name,
                'account_number' => $a->account_number, 'opening_date' => $a->opening_date?->toDateString(),
                'balance' => (string) $a->balance, 'comments' => $a->comments,
            ])->all(),
            'businessDate' => $this->businessDate($request),
        ]);
    }

    public function otherStore(Request $request, NumberingService $numbering, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'account_type' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:30', 'unique:other_accounts,account_number'],
            'opening_date' => ['required', 'date'],
            'balance' => ['required', 'numeric', 'min:0'],
            'comments' => ['required', 'string'],
        ]);
        if (empty($data['account_number'])) {
            $branchId = $request->user()->branch_id ?? \App\Models\Branch::where('type', 'Head Office')->value('id');
            $data['account_number'] = $numbering->nextDisplayId((int) $branchId, 'other_account');
        }
        $account = OtherAccount::create([...$data, 'created_by' => $request->user()->id]);
        $audit->log($request->user()->id, 'other_account.opened', 'other_accounts', $account->id, null, ['account_number' => $account->account_number]);

        return redirect()->route('deposits.other')->with('success', __('saved'));
    }
}
