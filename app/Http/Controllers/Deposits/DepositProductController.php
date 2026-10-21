<?php

namespace App\Http\Controllers\Deposits;

use App\Http\Controllers\Controller;
use App\Models\DepositProduct;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step 10 — deposit product master CRUD. Products are pure data: the
 * admin can edit or delete any of them (including the seeded Long Term
 * Savings sample) and the account engine follows the row, not the code.
 */
class DepositProductController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Deposits/Products', [
            'products' => DepositProduct::orderBy('code')->get()->map(fn (DepositProduct $p) => [
                'id' => $p->id, 'code' => $p->code, 'name_en' => $p->name_en, 'name_bn' => $p->name_bn,
                'is_compulsory' => $p->is_compulsory, 'default_profit_rate' => (string) $p->default_profit_rate,
                'minimum_balance' => (string) $p->minimum_balance,
                'minimum_opening_amount' => (string) $p->minimum_opening_amount,
                'withdrawal_rules' => $p->withdrawal_rules,
                'maturity_months' => $p->maturity_months,
                'premature_penalty_rate' => (string) $p->premature_penalty_rate,
                'auto_renew' => $p->auto_renew, 'gl_mapping_prefix' => $p->gl_mapping_prefix,
                'config' => $p->config, 'is_active' => $p->is_active,
            ])->all(),
        ]);
    }

    public function store(Request $request, AuditService $audit): RedirectResponse
    {
        $product = DepositProduct::create($this->validated($request));
        $audit->log($request->user()->id, 'deposit_product.created', 'deposit_products', $product->id, null, ['code' => $product->code]);

        return back()->with('success', __('saved'));
    }

    public function update(Request $request, DepositProduct $product, AuditService $audit): RedirectResponse
    {
        $product->update($this->validated($request, $product));
        $audit->log($request->user()->id, 'deposit_product.updated', 'deposit_products', $product->id, null, ['code' => $product->code]);

        return back()->with('success', __('saved'));
    }

    public function destroy(Request $request, DepositProduct $product, AuditService $audit): RedirectResponse
    {
        abort_if($product->accounts()->exists(), 422);
        $product->delete();
        $audit->log($request->user()->id, 'deposit_product.deleted', 'deposit_products', $product->id, null, ['code' => $product->code]);

        return back()->with('success', __('saved'));
    }

    private function validated(Request $request, ?DepositProduct $product = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'unique:deposit_products,code'.($product ? ','.$product->id : '')],
            'name_en' => ['required', 'string', 'max:255'],
            'name_bn' => ['nullable', 'string', 'max:255'],
            'is_compulsory' => ['nullable', 'boolean'],
            'default_profit_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'minimum_balance' => ['nullable', 'numeric', 'min:0'],
            'minimum_opening_amount' => ['nullable', 'numeric', 'min:0'],
            'withdrawal_rules' => ['nullable', 'array'],
            'maturity_months' => ['nullable', 'integer', 'min:1', 'max:600'],
            'premature_penalty_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'auto_renew' => ['nullable', 'boolean'],
            'gl_mapping_prefix' => ['required', 'string', 'max:60'],
            'config' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_compulsory'] = (bool) ($data['is_compulsory'] ?? false);
        $data['auto_renew'] = (bool) ($data['auto_renew'] ?? false);
        $data['is_active'] = (bool) ($data['is_active'] ?? true);
        $data['default_profit_rate'] = $data['default_profit_rate'] ?? 0;
        $data['minimum_balance'] = $data['minimum_balance'] ?? 0;
        $data['minimum_opening_amount'] = $data['minimum_opening_amount'] ?? 0;
        $data['premature_penalty_rate'] = $data['premature_penalty_rate'] ?? 0;

        return $data;
    }
}
