<?php

namespace Database\Seeders;

use App\Models\DepositProduct;
use App\Services\SettingsService;
use Illuminate\Database\Seeder;

/**
 * Step 10 — deposit product master seed. Rates initialise from the
 * `deposit.default_profit_rates` setting (0 until the admin sets real
 * figures). The Long Term Savings row carries TPDA's printed terms as a
 * SAMPLE config — every value is ordinary editable product data.
 */
class DepositProductSeeder extends Seeder
{
    public function run(): void
    {
        $rates = app(SettingsService::class)->get('deposit.default_profit_rates', []);
        $rate = fn (string $key) => is_array($rates) ? ($rates[$key] ?? 0) : 0;

        DepositProduct::updateOrCreate(['code' => 'SAVINGS'], [
            'name_en' => 'General Savings', 'name_bn' => 'সাধারণ সঞ্চয়',
            'is_compulsory' => false, 'default_profit_rate' => $rate('savings'),
            'gl_mapping_prefix' => 'deposit.savings', 'is_active' => true,
        ]);
        DepositProduct::updateOrCreate(['code' => 'CSAV'], [
            'name_en' => 'Compulsory Savings', 'name_bn' => 'বাধ্যতামূলক সঞ্চয়',
            'is_compulsory' => true, 'default_profit_rate' => $rate('savings'),
            'gl_mapping_prefix' => 'deposit.savings', 'is_active' => true,
        ]);
        DepositProduct::updateOrCreate(['code' => 'DPS'], [
            'name_en' => 'Deposit Pension Scheme', 'name_bn' => 'ডিপিএস',
            'is_compulsory' => false, 'default_profit_rate' => $rate('dps'),
            'gl_mapping_prefix' => 'deposit.dps', 'is_active' => true,
        ]);
        DepositProduct::updateOrCreate(['code' => 'FDR'], [
            'name_en' => 'Fixed Deposit', 'name_bn' => 'স্থায়ী আমানত',
            'is_compulsory' => false, 'default_profit_rate' => $rate('fdr'),
            'gl_mapping_prefix' => 'deposit.fdr', 'is_active' => true,
        ]);

        DepositProduct::updateOrCreate(['code' => 'LTS'], [
            'name_en' => 'Long Term Savings', 'name_bn' => 'দীর্ঘ মেয়াদী সঞ্চয়',
            'is_compulsory' => false, 'default_profit_rate' => 15,
            'gl_mapping_prefix' => 'deposit.dps', 'is_active' => true,
            'config' => $this->longTermSavingsConfig(),
        ]);
    }

    /**
     * TPDA's printed Long Term Savings terms, transcribed from the leaflet
     * (photo, 2026-10-21). Maturity profit = the printed table at the end of
     * the term; cross-checked rows: 50 → 456/1648/3889/7521/12963 and
     * 1000 → 9135/32991/77760/150479/259339. SAMPLE data only — the admin
     * edits or deletes it like any other product row.
     */
    private function longTermSavingsConfig(): array
    {
        return [
            'fixed_amounts' => [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 1000],
            'frequencies' => ['daily', 'weekly', 'monthly'],
            'terms_years' => [1, 2, 3, 4, 5],
            'collection_days_per_year' => 260,
            'maturity_profit_rates' => ['1' => 7, '2' => 9, '3' => 11, '4' => 13, '5' => 15],
            'payout_table' => [
                '50' => ['1' => 456, '2' => 1648, '3' => 3889, '4' => 7521, '5' => 12963],
                '100' => ['1' => 913, '2' => 3298, '3' => 7774, '4' => 15045, '5' => 25930],
                '200' => ['1' => 1827, '2' => 6598, '3' => 15551, '4' => 30094, '5' => 51866],
                '300' => ['1' => 2740, '2' => 9896, '3' => 23326, '4' => 45141, '5' => 77799],
                '400' => ['1' => 3654, '2' => 13196, '3' => 31103, '4' => 60190, '5' => 103704],
                '500' => ['1' => 4567, '2' => 16495, '3' => 38879, '4' => 75238, '5' => 129668],
                '600' => ['1' => 5481, '2' => 19794, '3' => 46655, '4' => 90286, '5' => 155602],
                '700' => ['1' => 6394, '2' => 23093, '3' => 54431, '4' => 105334, '5' => 181536],
                '800' => ['1' => 7308, '2' => 26392, '3' => 62207, '4' => 120382, '5' => 207470],
                '900' => ['1' => 8221, '2' => 29691, '3' => 69983, '4' => 135430, '5' => 233404],
                '1000' => ['1' => 9135, '2' => 32991, '3' => 77760, '4' => 150479, '5' => 259339],
            ],
            'premature_slabs' => [
                ['min_years' => 0, 'max_years' => 1, 'rate' => 0],
                ['min_years' => 1, 'max_years' => 2, 'rate' => 7],
                ['min_years' => 2, 'max_years' => 3, 'rate' => 9],
                ['min_years' => 3, 'max_years' => 4, 'rate' => 11],
                ['min_years' => 4, 'max_years' => 5, 'rate' => 13],
            ],
            'rules' => [
                'payout_within_months_after_maturity' => 1,
                'deposits_start' => 'day_after_approval',
                'holiday_collection' => 'roll_to_next_working_day',
                'late_deposit_makeup' => 'within_same_month',
                'multiple_accounts' => true,
                'nominees_allowed' => true,
                'on_membership_cancellation' => 'close_early_under_premature_slabs',
            ],
        ];
    }
}
