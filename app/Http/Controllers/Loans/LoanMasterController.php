<?php

namespace App\Http\Controllers\Loans;

use App\Http\Controllers\Controller;
use App\Models\EmiOptionTemplate;
use App\Models\InstallmentFrequency;
use App\Models\LoanProduct;
use App\Services\AuditService;
use App\Services\LoanRateGuard;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Step 15 — loan products master + Manage Installment Settings (EMI templates). */
class LoanMasterController extends Controller
{
    public function __construct(
        private LoanRateGuard $rateGuard,
        private SettingsService $settings,
    ) {}

    // ---- Loan products ----

    public function productsIndex(): Response
    {
        return Inertia::render('Loans/Products', [
            'products' => LoanProduct::orderBy('code')->get()->map(fn (LoanProduct $p) => $this->productRow($p))->all(),
            'frequencies' => InstallmentFrequency::orderBy('id')->get(['id', 'code', 'label_en', 'label_bn'])->all(),
            'defaultRate' => (string) ($this->settings->get('loan.default_profit_rate', 15) ?? 15),
            'cap' => (string) ($this->settings->get('loan.max_service_charge_rate', 24) ?? 24),
        ]);
    }

    public function storeProduct(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $this->validateProduct($request);
        $this->rateGuard->assertWithinCap((string) $data['profit_rate'], $data['rate_method']);
        $product = LoanProduct::create($data);
        $audit->log($request->user()->id, 'loan.product_created', 'loan_products', $product->id, null, ['code' => $product->code]);

        return back()->with('success', __('saved'));
    }

    public function updateProduct(Request $request, LoanProduct $product, AuditService $audit): RedirectResponse
    {
        $data = $this->validateProduct($request, $product->id);
        $this->rateGuard->assertWithinCap((string) $data['profit_rate'], $data['rate_method']);
        $product->update($data);
        $audit->log($request->user()->id, 'loan.product_updated', 'loan_products', $product->id, null, []);

        return back()->with('success', __('saved'));
    }

    public function destroyProduct(LoanProduct $product, AuditService $audit, Request $request): RedirectResponse
    {
        if (EmiOptionTemplate::where('loan_product_id', $product->id)->exists()) {
            throw ValidationException::withMessages(['product' => __('product_in_use')]);
        }
        $product->delete();
        $audit->log($request->user()->id, 'loan.product_deleted', 'loan_products', $product->id, null, []);

        return back()->with('success', __('saved'));
    }

    private function validateProduct(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:loan_products,code'.($ignoreId ? ','.$ignoreId : '')],
            'name_en' => ['required', 'string', 'max:255'],
            'name_bn' => ['nullable', 'string', 'max:255'],
            'profit_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'rate_method' => ['required', 'in:flat,declining'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_amount' => ['nullable', 'numeric', 'min:0'],
            'min_term' => ['nullable', 'integer', 'min:1'],
            'max_term' => ['nullable', 'integer', 'min:1'],
            'allowed_frequencies' => ['nullable', 'array'],
            'allowed_frequencies.*' => ['string'],
            'grace_days' => ['nullable', 'integer', 'min:0'],
            'penal_rate' => ['nullable', 'numeric', 'min:0'],
            'gl_mapping_prefix' => ['required', 'string', 'max:60'],
            'min_cycle' => ['nullable', 'integer', 'min:0'],
            'max_cycle' => ['nullable', 'integer', 'min:0'],
            'min_savings_balance' => ['nullable', 'numeric', 'min:0'],
            'max_concurrent_loans' => ['nullable', 'integer', 'min:1'],
            'min_member_months' => ['nullable', 'integer', 'min:0'],
            'fees' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        // Rate fallback only at creation (plan precedence): product rate,
        // else the settings default.
        $data['profit_rate'] = $data['profit_rate'] ?? $this->settings->get('loan.default_profit_rate', 15);
        $data['penalty_rule'] = ['grace_days' => (int) ($data['grace_days'] ?? 0), 'penal_rate' => (float) ($data['penal_rate'] ?? 0)];
        $data['fee_config'] = ['fees' => array_values(array_filter(array_map('trim', explode(',', (string) ($data['fees'] ?? '')))))];
        $data['allowed_frequencies'] = $data['allowed_frequencies'] ?? ['D', 'W', 'M'];
        $data['is_active'] = (bool) ($data['is_active'] ?? true);
        unset($data['grace_days'], $data['penal_rate'], $data['fees']);

        return $data;
    }

    private function productRow(LoanProduct $p): array
    {
        return [
            'id' => $p->id, 'code' => $p->code, 'name_en' => $p->name_en, 'name_bn' => $p->name_bn,
            'profit_rate' => (string) $p->profit_rate, 'rate_method' => $p->rate_method,
            'min_amount' => (string) $p->min_amount, 'max_amount' => (string) $p->max_amount,
            'min_term' => $p->min_term, 'max_term' => $p->max_term,
            'allowed_frequencies' => $p->allowed_frequencies ?? [],
            'grace_days' => $p->penalty_rule['grace_days'] ?? 0, 'penal_rate' => $p->penalty_rule['penal_rate'] ?? 0,
            'gl_mapping_prefix' => $p->gl_mapping_prefix,
            'min_cycle' => $p->min_cycle, 'max_cycle' => $p->max_cycle,
            'min_savings_balance' => (string) $p->min_savings_balance,
            'max_concurrent_loans' => $p->max_concurrent_loans, 'min_member_months' => $p->min_member_months,
            'fees' => implode(',', $p->fee_config['fees'] ?? []),
            'is_active' => $p->is_active,
            'effective_rate' => $this->rateGuard->effectiveRatePercent((string) $p->profit_rate, $p->rate_method),
        ];
    }

    // ---- Manage Installment Settings (EMI option templates) ----

    public function emiIndex(Request $request): Response
    {
        $query = EmiOptionTemplate::with('product')->orderBy('loan_product_id')->orderBy('id');
        if ($request->filled('loan_product_id')) {
            $query->where('loan_product_id', $request->integer('loan_product_id'));
        }
        if ($request->filled('search')) {
            $needle = (string) $request->query('search');
            $query->whereHas('product', fn ($q) => $q->where('code', 'like', "%{$needle}%")->orWhere('name_en', 'like', "%{$needle}%"));
        }

        return Inertia::render('Loans/InstallmentSettings', [
            'templates' => $query->get()->map(fn (EmiOptionTemplate $t) => [
                'id' => $t->id, 'loan_product_id' => $t->loan_product_id,
                'product' => $t->product ? $t->product->code.' — '.$t->product->name_en : null,
                'frequency' => $t->frequency, 'no_of_installments' => $t->no_of_installments,
                'profit_rate' => (string) $t->profit_rate, 'comments' => $t->comments,
                'is_active' => $t->is_active,
            ])->all(),
            'products' => LoanProduct::where('is_active', true)->orderBy('code')
                ->get(['id', 'code', 'name_en', 'profit_rate'])->all(),
            'frequencies' => collect(InstallmentFrequency::enabled())
                ->map(fn ($f) => ['code' => $f->code, 'label_en' => $f->label_en, 'label_bn' => $f->label_bn])->all(),
            'canOverride' => $request->user()->can('loans.rate-override'),
            'filters' => ['loan_product_id' => $request->integer('loan_product_id') ?: null, 'search' => (string) $request->query('search', '')],
        ]);
    }

    public function storeEmi(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'loan_product_id' => ['required', 'integer', 'exists:loan_products,id'],
            'frequency' => ['required', 'string', 'exists:installment_frequencies,code'],
            'no_of_installments' => ['required', 'integer', 'min:1'],
            'profit_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'comments' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $this->assertTemplateRate($request, LoanProduct::findOrFail($data['loan_product_id']), (string) $data['profit_rate']);
        $this->rateGuard->assertWithinCap((string) $data['profit_rate'], LoanProduct::findOrFail($data['loan_product_id'])->rate_method);
        $template = EmiOptionTemplate::create([...$data, 'is_active' => (bool) ($data['is_active'] ?? true)]);
        $audit->log($request->user()->id, 'loan.emi_template_created', 'emi_option_templates', $template->id, null, []);

        return back()->with('success', __('saved'));
    }

    public function updateEmi(Request $request, EmiOptionTemplate $template, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'frequency' => ['required', 'string', 'exists:installment_frequencies,code'],
            'no_of_installments' => ['required', 'integer', 'min:1'],
            'profit_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'comments' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $this->assertTemplateRate($request, $template->product, (string) $data['profit_rate']);
        $template->update([...$data, 'is_active' => (bool) ($data['is_active'] ?? true)]);
        $audit->log($request->user()->id, 'loan.emi_template_updated', 'emi_option_templates', $template->id, null, []);

        return back()->with('success', __('saved'));
    }

    public function destroyEmi(EmiOptionTemplate $template, AuditService $audit, Request $request): RedirectResponse
    {
        $template->delete();
        $audit->log($request->user()->id, 'loan.emi_template_deleted', 'emi_option_templates', $template->id, null, []);

        return back()->with('success', __('saved'));
    }

    /** Template rate must equal the product rate unless the user may override. */
    private function assertTemplateRate(Request $request, LoanProduct $product, string $rate): void
    {
        if ((float) $rate !== (float) $product->profit_rate && ! $request->user()->can('loans.rate-override')) {
            throw ValidationException::withMessages(['profit_rate' => __('rate_must_match_product')]);
        }
    }
}
