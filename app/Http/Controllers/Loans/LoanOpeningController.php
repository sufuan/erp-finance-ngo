<?php

namespace App\Http\Controllers\Loans;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\InstallmentFrequency;
use App\Models\LoanAccount;
use App\Models\LoanProduct;
use App\Models\LoanProposal;
use App\Models\Member;
use App\Services\AuditService;
use App\Services\BusinessDayService;
use App\Services\LoanOpeningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Step 17 — loan opening for members / staff / product (invoice) loans. */
class LoanOpeningController extends Controller
{
    public function __construct(private LoanOpeningService $opening) {}

    public function create(Request $request): Response
    {
        $mode = (string) $request->query('mode', 'member');
        $proposal = $request->filled('proposal_id')
            ? LoanProposal::with(['member', 'product'])->find($request->integer('proposal_id')) : null;
        $member = $proposal?->member ?? $this->findMember($request);
        $employee = $request->filled('employee')
            ? Employee::where('employee_code', $request->query('employee'))
                ->orWhere('id', ctype_digit((string) $request->query('employee')) ? (int) $request->query('employee') : -1)
                ->first() : null;

        return Inertia::render('Loans/Opening', [
            'mode' => $mode,
            'proposal' => $proposal ? [
                'id' => $proposal->id, 'member_id' => $proposal->member_id,
                'loan_product_id' => $proposal->loan_product_id,
                'loan_amount' => (string) $proposal->loan_amount,
                'loan_purpose' => $proposal->loan_purpose, 'frequency' => $proposal->frequency,
                'no_of_installments' => $proposal->no_of_installments,
                'profit_rate' => (string) $proposal->profit_rate,
                'installment_from_date' => $proposal->installment_from_date?->toDateString(),
                'guarantors' => $proposal->guarantors ?? [],
            ] : null,
            'member' => $member ? [
                'id' => $member->id, 'member_no' => $member->member_no, 'name' => $member->name,
                'mobile' => $member->mobile,
                'samity' => $member->samity ? $member->samity->samity_code.' — '.$member->samity->name : null,
                'share_amount' => (string) $member->share_amount, 'status' => $member->status,
            ] : null,
            'memberQuery' => (string) $request->query('member', ''),
            'employee' => $employee ? [
                'id' => $employee->id, 'employee_code' => $employee->employee_code,
                'name' => $employee->name, 'mobile' => $employee->mobile,
                'designation' => $employee->designation?->name,
            ] : null,
            'employeeQuery' => (string) $request->query('employee', ''),
            'products' => LoanProduct::where('is_active', true)->orderBy('code')->get()
                ->map(fn (LoanProduct $p) => [
                    'id' => $p->id, 'code' => $p->code, 'name_en' => $p->name_en,
                    'profit_rate' => (string) $p->profit_rate, 'rate_method' => $p->rate_method,
                    'allowed_frequencies' => $p->allowed_frequencies ?? [],
                    'fee_defaults' => collect($p->fee_config['fees'] ?? [])->mapWithKeys(fn ($f) => [$f => 0])->all(),
                ])->all(),
            'frequencies' => collect(InstallmentFrequency::enabled())
                ->map(fn ($f) => ['code' => $f->code, 'label_en' => $f->label_en, 'label_bn' => $f->label_bn])->all(),
            'feeTypes' => ['form', 'health', 'risk', 'document', 'emi_locker', 'others'],
            'canOverride' => $request->user()->can('loans.rate-override'),
            'businessDate' => $this->businessDate($member),
            'productLoans' => $mode === 'product' ? $this->productLoanRows() : [],
        ]);
    }

    public function store(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'member_id' => ['nullable', 'integer', 'exists:members,id'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'loan_proposal_id' => ['nullable', 'integer', 'exists:loan_proposals,id'],
            'loan_product_id' => ['required', 'integer', 'exists:loan_products,id'],
            'opening_date' => ['required', 'date'],
            'loan_purpose' => ['nullable', 'string', 'max:255'],
            'loan_amount' => ['required', 'numeric', 'gt:0'],
            'down_payment' => ['nullable', 'numeric', 'min:0'],
            'block_account' => ['nullable', 'boolean'],
            'frequency' => ['required', 'string'],
            'no_of_installments' => ['required', 'integer', 'min:1'],
            'profit_rate' => ['nullable', 'numeric', 'min:0'],
            'installment_from_date' => ['required', 'date'],
            'payment_type' => ['nullable', 'string', 'max:50'],
            'payment_to_account' => ['nullable', 'string', 'max:100'],
            'invoice_number' => ['nullable', 'string', 'max:100'],
            'installment_reminder' => ['nullable', 'boolean'],
            'overdue_reminder' => ['nullable', 'boolean'],
            'fees' => ['nullable', 'array'],
            'fees.*' => ['nullable', 'numeric', 'min:0'],
            'guarantors' => ['nullable', 'array'],
            'guarantors.*.name' => ['nullable', 'string', 'max:255'],
            'guarantors.*.relation' => ['nullable', 'string', 'max:100'],
            'guarantors.*.phone' => ['nullable', 'string', 'max:30'],
            'guarantors.*.address' => ['nullable', 'string', 'max:255'],
            'guarantors.*.national_id' => ['nullable', 'string', 'max:30'],
        ]);
        $data['profit_rate'] = isset($data['profit_rate']) ? (string) $data['profit_rate'] : null;
        $data['loan_amount'] = (string) $data['loan_amount'];
        $loan = $this->opening->open($data, $request->user());
        $audit->log($request->user()->id, 'loan.opened', 'loan_accounts', $loan->id, null, ['loan_number' => $loan->loan_number]);

        return redirect()->route('loans.opening', ['mode' => $request->query('mode', 'member')])
            ->with('success', __('saved'));
    }

    private function productLoanRows(): array
    {
        return LoanAccount::with(['member', 'product'])->whereNotNull('invoice_number')
            ->orderByDesc('id')->limit(50)->get()->map(fn (LoanAccount $l) => [
                'id' => $l->id, 'loan_number' => $l->loan_number,
                'member' => $l->member ? $l->member->member_no.' — '.$l->member->name : null,
                'invoice_number' => $l->invoice_number, 'frequency' => $l->frequency,
                'installment_from_date' => $l->installment_from_date?->toDateString(),
                'loan_amount' => (string) $l->loan_amount, 'profit_rate' => (string) $l->profit_rate,
                'profit_amount' => (string) $l->profit_amount, 'total_repayment' => (string) $l->total_repayment,
                'no_of_installments' => $l->no_of_installments,
                'installment_amount' => (string) $l->installment_amount,
                'paid' => (string) $l->schedules()->sum(\Illuminate\Support\Facades\DB::raw('paid_principal + paid_profit')),
                'installment_expire_date' => $l->installment_expire_date?->toDateString(),
                'status' => $l->status,
            ])->all();
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

    private function businessDate(?Member $member): string
    {
        $branchId = $member?->branch_id ?? (int) \App\Models\Branch::where('type', 'Head Office')->value('id');

        return app(BusinessDayService::class)->currentDate($branchId);
    }
}
