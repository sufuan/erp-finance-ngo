<?php

namespace App\Http\Controllers\Loans;

use App\Http\Controllers\Controller;
use App\Models\InsuranceClaim;
use App\Models\LoanAccount;
use App\Models\Member;
use App\Services\LoanAdjustmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Step 19 — loan insurance / risk-fund claims. */
class InsuranceClaimController extends Controller
{
    public function __construct(private LoanAdjustmentService $adjust) {}

    public function index(Request $request): Response
    {
        $claims = InsuranceClaim::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->with(['member', 'loan'])
            ->latest('id')->paginate(25)->withQueryString()
            ->through(fn ($c) => [
                'id' => $c->id, 'member' => $c->member?->name,
                'loan_number' => $c->loan?->loan_number, 'claim_type' => $c->claim_type,
                'claim_date' => $c->claim_date?->toDateString(), 'amount' => $c->amount,
                'status' => $c->status,
            ]);

        return Inertia::render('Loans/InsuranceClaims', [
            'claims' => $claims,
            'filters' => ['status' => $request->string('status')->value()],
            'members' => Member::where('status', 'Active')->orderBy('name')->limit(200)
                ->get()->map(fn ($m) => ['id' => $m->id, 'label' => $m->name.' ('.$m->member_no.')']),
            'loans' => LoanAccount::whereIn('status', ['Disbursed', 'Active'])->orderByDesc('id')->limit(200)
                ->get()->map(fn ($l) => ['id' => $l->id, 'label' => $l->loan_number]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'member_id' => ['required', 'integer'],
            'loan_account_id' => ['nullable', 'integer'],
            'claim_type' => ['required', 'in:death,disability'],
            'claim_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0'],
            'documents' => ['nullable', 'array'],
        ]);
        $data['documents'] = array_values($data['documents'] ?? []);
        $data['status'] = 'Filed';
        InsuranceClaim::create($data);

        return back()->with('success', __('saved_successfully'));
    }

    public function decide(Request $request, InsuranceClaim $claim): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject']]);
        $this->adjust->decideClaim($claim, $data['decision'], $request->user());

        return back()->with('success', __('saved_successfully'));
    }

    public function pay(Request $request, InsuranceClaim $claim): RedirectResponse
    {
        $this->adjust->payClaim($claim, $request->user());

        return back()->with('success', __('saved_successfully'));
    }
}
