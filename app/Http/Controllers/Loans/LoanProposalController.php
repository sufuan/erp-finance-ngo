<?php

namespace App\Http\Controllers\Loans;

use App\Http\Controllers\Controller;
use App\Models\DepositAccount;
use App\Models\InstallmentFrequency;
use App\Models\LoanProduct;
use App\Models\LoanProposal;
use App\Models\Member;
use App\Models\Samity;
use App\Services\AuditService;
use App\Services\BusinessDayService;
use App\Services\LoanProposalService;
use App\Services\ScheduleEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Step 16 — loan proposal intake, schedule preview, approval queue. */
class LoanProposalController extends Controller
{
    public function __construct(
        private LoanProposalService $proposals,
        private ScheduleEngine $schedule,
    ) {}

    public function create(Request $request): Response
    {
        $member = $this->findMember($request);
        $products = LoanProduct::where('is_active', true)->orderBy('code')->get();

        return Inertia::render('Loans/ProposalCreate', [
            'member' => $member ? $this->memberPayload($member) : null,
            'memberQuery' => (string) $request->query('member', ''),
            'products' => $products->map(fn (LoanProduct $p) => [
                'id' => $p->id, 'code' => $p->code, 'name_en' => $p->name_en, 'name_bn' => $p->name_bn,
                'profit_rate' => (string) $p->profit_rate, 'rate_method' => $p->rate_method,
                'min_amount' => (string) $p->min_amount, 'max_amount' => (string) $p->max_amount,
                'min_term' => $p->min_term, 'max_term' => $p->max_term,
                'allowed_frequencies' => $p->allowed_frequencies ?? [],
            ])->all(),
            'frequencies' => collect(InstallmentFrequency::enabled())
                ->map(fn ($f) => ['code' => $f->code, 'label_en' => $f->label_en, 'label_bn' => $f->label_bn])->all(),
            'fdrAccounts' => $member ? DepositAccount::with('product:id,code')
                ->where('member_id', $member->id)->whereNull('closing_date')
                ->whereHas('product', fn ($q) => $q->where('code', 'FDR'))
                ->get()->map(fn (DepositAccount $a) => [
                    'id' => $a->id, 'account_number' => $a->account_number,
                    'current_balance' => (string) $a->current_balance,
                ])->all() : [],
            'canOverride' => $request->user()->can('loans.rate-override'),
            'businessDate' => $this->businessDate($member),
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'loan_product_id' => ['required', 'integer', 'exists:loan_products,id'],
            'loan_amount' => ['required', 'numeric', 'gt:0'],
            'profit_rate' => ['nullable', 'numeric', 'min:0'],
            'frequency' => ['required', 'string'],
            'no_of_installments' => ['required', 'integer', 'min:1'],
            'installment_from_date' => ['required', 'date'],
        ]);
        $product = LoanProduct::findOrFail($data['loan_product_id']);
        $rate = (string) ($data['profit_rate'] ?? $product->profit_rate);

        return response()->json($this->schedule->preview(
            (string) $data['loan_amount'], $rate, $product->rate_method,
            $data['frequency'], (int) $data['no_of_installments'], $data['installment_from_date'],
        ));
    }

    public function store(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'member_id' => ['required', 'integer', 'exists:members,id'],
            'loan_product_id' => ['required', 'integer', 'exists:loan_products,id'],
            'proposal_date' => ['required', 'date'],
            'loan_purpose' => ['nullable', 'string', 'max:255'],
            'loan_amount' => ['required', 'numeric', 'gt:0'],
            'fdr_account_id' => ['nullable', 'integer', 'exists:deposit_accounts,id'],
            'frequency' => ['required', 'string'],
            'no_of_installments' => ['required', 'integer', 'min:1'],
            'profit_rate' => ['nullable', 'numeric', 'min:0'],
            'installment_from_date' => ['required', 'date'],
            'guarantors' => ['nullable', 'array'],
            'guarantors.*.name' => ['nullable', 'string', 'max:255'],
            'guarantors.*.relation' => ['nullable', 'string', 'max:100'],
            'guarantors.*.phone' => ['nullable', 'string', 'max:30'],
            'guarantors.*.address' => ['nullable', 'string', 'max:255'],
            'guarantors.*.national_id' => ['nullable', 'string', 'max:30'],
        ]);
        $proposal = $this->proposals->submit(Member::findOrFail($data['member_id']), [
            'loan_product_id' => $data['loan_product_id'],
            'proposal_date' => $data['proposal_date'],
            'loan_purpose' => $data['loan_purpose'] ?? null,
            'loan_amount' => (string) $data['loan_amount'],
            'fdr_account_id' => $data['fdr_account_id'] ?? null,
            'frequency' => $data['frequency'],
            'no_of_installments' => $data['no_of_installments'],
            'profit_rate' => isset($data['profit_rate']) ? (string) $data['profit_rate'] : null,
            'installment_from_date' => $data['installment_from_date'],
            'guarantors' => $data['guarantors'] ?? null,
        ], $request->user());
        $audit->log($request->user()->id, 'loan.proposal_created', 'loan_proposals', $proposal->id, null, ['amount' => $proposal->loan_amount]);

        return redirect()->route('loans.proposals')->with('success', __('saved'));
    }

    public function index(Request $request): Response
    {
        $query = LoanProposal::with(['member', 'samity', 'product', 'branch'])->orderByDesc('id');
        if ($request->filled('samity_id')) {
            $query->where('samity_id', $request->integer('samity_id'));
        }
        if ($request->filled('field_officer_id')) {
            $query->whereHas('samity', fn ($q) => $q->where('field_officer_id', $request->integer('field_officer_id')));
        }
        if ($request->filled('member')) {
            $needle = trim((string) $request->query('member'));
            $query->whereHas('member', fn ($q) => $q->where('member_no', $needle)
                ->orWhere('id', ctype_digit($needle) ? (int) $needle : -1));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('from_date')) {
            $query->where('proposal_date', '>=', $request->query('from_date'));
        }
        if ($request->filled('to_date')) {
            $query->where('proposal_date', '<=', $request->query('to_date'));
        }

        return Inertia::render('Loans/ProposalList', [
            'proposals' => $query->limit(100)->get()->map(fn (LoanProposal $p) => [
                'id' => $p->id, 'branch_code' => $p->branch?->code,
                'samity' => $p->samity ? $p->samity->samity_code.' — '.$p->samity->name : null,
                'member' => $p->member ? $p->member->member_no.' — '.$p->member->name : null,
                'account_type' => $p->product ? $p->product->code.' — '.$p->product->name_en : null,
                'profit_rate' => (string) $p->profit_rate,
                'proposal_date' => $p->proposal_date?->toDateString(),
                'loan_purpose' => $p->loan_purpose, 'loan_amount' => (string) $p->loan_amount,
                'frequency' => $p->frequency, 'installment_amount' => (string) $p->installment_amount,
                'installment_from_date' => $p->installment_from_date?->toDateString(),
                'no_of_installments' => $p->no_of_installments,
                'installment_expire_date' => $p->installment_expire_date?->toDateString(),
                'status' => $p->status,
            ])->all(),
            'samities' => Samity::orderBy('name')->get(['id', 'samity_code', 'name'])->all(),
            'officers' => \App\Models\Employee::orderBy('name')->get(['id', 'employee_code', 'name'])->all(),
            'filters' => $request->only(['samity_id', 'field_officer_id', 'member', 'status', 'from_date', 'to_date']),
            'canApprove' => $request->user()->can('loans.approve'),
        ]);
    }

    public function approve(Request $request, LoanProposal $proposal, AuditService $audit): RedirectResponse
    {
        $this->proposals->approve($proposal, $request->user());
        $audit->log($request->user()->id, 'loan.proposal_approved', 'loan_proposals', $proposal->id, null, []);

        return back()->with('success', __('saved'));
    }

    public function reject(Request $request, LoanProposal $proposal, AuditService $audit): RedirectResponse
    {
        $this->proposals->reject($proposal, $request->user());
        $audit->log($request->user()->id, 'loan.proposal_rejected', 'loan_proposals', $proposal->id, null, []);

        return back()->with('success', __('saved'));
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

    private function memberPayload(Member $member): array
    {
        $savings = DepositAccount::where('member_id', $member->id)->whereNull('closing_date')->sum('current_balance');

        return [
            'id' => $member->id, 'member_no' => $member->member_no, 'name' => $member->name,
            'mobile' => $member->mobile, 'samity' => $member->samity ? $member->samity->samity_code.' — '.$member->samity->name : null,
            'share_amount' => (string) $member->share_amount, 'savings_balance' => (string) $savings,
            'status' => $member->status,
        ];
    }

    private function businessDate(?Member $member): string
    {
        $branchId = $member?->branch_id ?? (int) \App\Models\Branch::where('type', 'Head Office')->value('id');

        return app(BusinessDayService::class)->currentDate($branchId);
    }
}
