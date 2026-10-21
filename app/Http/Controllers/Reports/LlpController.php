<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LoanLossProvision;
use App\Models\LoanProduct;
use App\Models\Samity;
use App\Services\BusinessDayService;
use App\Services\LlpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Step 20 — Loan Loss Provisioning: compute, review, post the journal. */
class LlpController extends Controller
{
    public function __construct(
        private LlpService $llp,
        private BusinessDayService $businessDays,
    ) {}

    public function index(Request $request): Response
    {
        $filters = [
            'account_type' => $request->query('account_type'),
            'samity_id' => $request->query('samity_id'),
            'employee_id' => $request->query('employee_id'),
            'member' => $request->query('member'),
            'frequency' => $request->query('frequency'),
            'report_date' => $request->query('report_date') ?: now()->toDateString(),
        ];
        $computed = $request->boolean('run') ? $this->llp->compute($filters) : ['rows' => [], 'totals' => ['outstanding' => '0.00', 'required' => '0.00']];

        return Inertia::render('Reports/Llp', [
            'filters' => $filters,
            'ran' => $request->boolean('run'),
            'rows' => $computed['rows'],
            'totals' => $computed['totals'],
            'provision_balance' => $this->llp->provisionBalance(),
            'runs' => LoanLossProvision::latest('id')->limit(20)->get()
                ->map(fn ($r) => [
                    'id' => $r->id, 'account_type' => $r->account_type ?? 'all',
                    'report_date' => $r->report_date?->toDateString(),
                    'outstanding' => $r->outstanding, 'required_llp' => $r->required_llp,
                    'actual_llp' => $r->actual_llp, 'coverage' => $r->coverage,
                    'voucher_id' => $r->voucher_id,
                ]),
            'products' => LoanProduct::orderBy('code')->get(['id', 'code', 'name_en'])->all(),
            'samities' => Samity::orderBy('name')->get(['id', 'samity_code', 'name'])->all(),
            'employees' => Employee::orderBy('name')->get(['id', 'name', 'employee_code'])->all(),
            'frequencies' => ['D', 'W', 'M', 'Q', 'H', 'Y', 'F-15days'],
        ]);
    }

    public function post(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'account_type' => ['nullable', 'integer'],
            'samity_id' => ['nullable', 'integer'],
            'employee_id' => ['nullable', 'integer'],
            'member' => ['nullable', 'string'],
            'frequency' => ['nullable', 'string'],
            'report_date' => ['required', 'date'],
        ]);
        $run = $this->llp->postRun($data, $request->user());

        return redirect()->route('reports.llp', ['run' => 1, 'report_date' => $data['report_date']])
            ->with('success', __('saved_successfully').' — LLP #'.$run->id);
    }
}
