<?php

namespace Database\Seeders;

use App\Models\EmiOptionTemplate;
use App\Models\InstallmentFrequency;
use App\Models\LoanProduct;
use Illuminate\Database\Seeder;

/**
 * Step 15 — loan masters. TPDA seeds: three products at the TPDA default
 * 15% DECLINING (the MRA-compliant basis — 15% flat would breach the 24%
 * effective cap), the full frequency master (enabled set lives in the
 * `loan.enabled_frequencies` setting), and two starter EMI templates.
 * All figures are editable product data.
 */
class LoanMasterSeeder extends Seeder
{
    public function run(): void
    {
        $frequencies = [
            ['D', 'Daily', 'দৈনিক', 1],
            ['W', 'Weekly', 'সাপ্তাহিক', 7],
            ['M', 'Monthly', 'মাসিক', 30],
            ['Q', 'Quarterly', 'ত্রৈমাসিক', 90],
            ['H', 'Half-Yearly', 'ষাণ্মাসিক', 180],
            ['Y', 'Yearly', 'বার্ষিক', 365],
            ['AtMaturity', 'At Maturity', 'মেয়াদপূর্তিতে', 0],
            ['F-15days', 'Every 15 Days', 'প্রতি ১৫ দিন', 15],
            ['S-single', 'Single Payment', 'একক পরিশোধ', 0],
            ['N-na', 'Not Applicable', 'প্রযোজ্য নয়', 0],
        ];
        foreach ($frequencies as [$code, $en, $bn, $days]) {
            InstallmentFrequency::updateOrCreate(['code' => $code], [
                'label_en' => $en, 'label_bn' => $bn, 'days_interval' => $days,
            ]);
        }

        $products = [
            ['3001', 'General Loan', 'সাধারণ ঋণ', 'loan.general'],
            ['3002', 'Product Loan', 'পণ্য ঋণ', 'loan.product'],
            ['3003', 'Agrosor Loan', 'অগ্রসর ঋণ', 'loan.agrosor'],
        ];
        foreach ($products as [$code, $en, $bn, $prefix]) {
            LoanProduct::updateOrCreate(['code' => $code], [
                'name_en' => $en, 'name_bn' => $bn,
                'profit_rate' => 15, 'rate_method' => 'declining',
                'min_amount' => 1000, 'max_amount' => 500000,
                'min_term' => 1, 'max_term' => 100,
                'allowed_frequencies' => ['D', 'W', 'M'],
                'penalty_rule' => ['grace_days' => 0, 'penal_rate' => 0],
                'gl_mapping_prefix' => $prefix,
                'min_cycle' => 0, 'max_cycle' => null, 'min_savings_balance' => 0,
                'max_concurrent_loans' => 1, 'min_member_months' => 0,
                'fee_config' => ['fees' => ['form', 'health', 'risk', 'document', 'emi_locker', 'others']],
                'is_active' => true,
            ]);
        }

        $general = LoanProduct::where('code', '3001')->first();
        if ($general) {
            EmiOptionTemplate::updateOrCreate(
                ['loan_product_id' => $general->id, 'frequency' => 'M', 'no_of_installments' => 12],
                ['profit_rate' => 15, 'comments' => 'Standard monthly', 'is_active' => true],
            );
            EmiOptionTemplate::updateOrCreate(
                ['loan_product_id' => $general->id, 'frequency' => 'W', 'no_of_installments' => 50],
                ['profit_rate' => 15, 'comments' => 'Standard weekly', 'is_active' => true],
            );
        }
    }
}
