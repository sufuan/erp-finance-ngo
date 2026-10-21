<?php

namespace App\Http\Controllers\Loans;

use App\Http\Controllers\Controller;
use App\Models\DuePenaltySetup;
use App\Models\LoanAccount;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Step 17 — Due Penalty Setup: per-loan penal charge. */
class DuePenaltyController extends Controller
{
    public function index(Request $request): Response
    {
        $query = LoanAccount::with(['member', 'product'])->orderByDesc('id');
        if ($request->filled('samity_id')) {
            $query->whereHas('member', fn ($q) => $q->where('samity_id', $request->integer('samity_id')));
        }
        if ($request->filled('loan_product_id')) {
            $query->where('loan_product_id', $request->integer('loan_product_id'));
        }
        if ($request->filled('member')) {
            $needle = trim((string) $request->query('member'));
            $query->whereHas('member', fn ($q) => $q->where('member_no', $needle)
                ->orWhere('id', ctype_digit($needle) ? (int) $needle : -1));
        }
        if ($request->filled('account')) {
            $query->where('loan_number', trim((string) $request->query('account')));
        }
        $setups = DuePenaltySetup::pluck('penal_charge', 'loan_account_id');

        return Inertia::render('Loans/DuePenaltySetup', [
            'loans' => $query->limit(50)->get()->map(fn (LoanAccount $l) => [
                'id' => $l->id, 'loan_number' => $l->loan_number,
                'member' => $l->member ? $l->member->member_no.' — '.$l->member->name : null,
                'address' => $l->member?->present_address,
                'opening_date' => $l->opening_date?->toDateString(),
                'loan_amount' => (string) $l->loan_amount,
                'installment_amount' => (string) $l->installment_amount,
                'due_amount' => (string) $l->schedules()->where('status', '!=', 'Paid')
                    ->sum(\Illuminate\Support\Facades\DB::raw('total_due - paid_principal - paid_profit')),
                'penal_charge' => isset($setups[$l->id]) ? (string) $setups[$l->id] : '0.00',
            ])->all(),
            'products' => \App\Models\LoanProduct::orderBy('code')->get(['id', 'code', 'name_en'])->all(),
            'samities' => \App\Models\Samity::orderBy('name')->get(['id', 'samity_code', 'name'])->all(),
            'filters' => $request->only(['samity_id', 'loan_product_id', 'member', 'account']),
        ]);
    }

    public function update(Request $request, LoanAccount $loan, AuditService $audit): RedirectResponse
    {
        $data = $request->validate(['penal_charge' => ['required', 'numeric', 'min:0']]);
        DuePenaltySetup::updateOrCreate(
            ['loan_account_id' => $loan->id],
            ['penal_charge' => $data['penal_charge'], 'updated_by' => $request->user()->id, 'updated_at' => now()],
        );
        $audit->log($request->user()->id, 'loan.penalty_setup', 'due_penalty_setups', $loan->id, null, ['penal_charge' => $data['penal_charge']]);

        return back()->with('success', __('saved'));
    }
}
