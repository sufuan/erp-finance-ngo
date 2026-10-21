<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/**
 * Step 15 — the MRA service-charge cap (binding Round-5 rule). The cap in
 * `settings.loan.max_service_charge_rate` (24%) applies to the
 * DECLINING-EQUIVALENT EFFECTIVE rate:
 *  - declining products: the nominal rate IS the effective rate;
 *  - flat products: the effective rate implied by the flat schedule —
 *    the APR (monthly IRR × 12) of a 1-year monthly loan at that flat
 *    rate (≈1.8× the flat rate; 15% flat ≈ 26.6% → over the cap).
 */
class LoanRateGuard
{
    public function __construct(private SettingsService $settings) {}

    /** Declining-equivalent effective annual rate, in percent. */
    public function effectiveRatePercent(string $ratePercent, string $rateMethod): float
    {
        $rate = (float) $ratePercent;
        if ($rateMethod !== 'flat' || $rate <= 0) {
            return $rate;
        }
        // Flat schedule on a notional 120,000 over 12 monthly installments:
        // total repayable = P × (1 + f), installment = total / 12. Solve the
        // monthly IRR by bisection, then express as APR (i × 12).
        $principal = 120000.0;
        $installment = $principal * (1 + $rate / 100) / 12;
        $lo = 0.0;
        $hi = 0.20;
        for ($n = 0; $n < 80; $n++) {
            $mid = ($lo + $hi) / 2;
            $pv = $installment * (1 - pow(1 + $mid, -12)) / $mid;
            if ($pv > $principal) {
                $lo = $mid;
            } else {
                $hi = $mid;
            }
        }
        $monthlyIrr = ($lo + $hi) / 2;

        return round($monthlyIrr * 12 * 100, 2);
    }

    /** @throws ValidationException when the effective rate breaks the cap */
    public function assertWithinCap(string $ratePercent, string $rateMethod): void
    {
        $cap = (float) ($this->settings->get('loan.max_service_charge_rate', 24) ?? 24);
        $effective = $this->effectiveRatePercent($ratePercent, $rateMethod);
        if ($effective > $cap) {
            throw ValidationException::withMessages([
                'profit_rate' => __('mra_cap_exceeded', ['cap' => $cap, 'effective' => $effective]),
            ]);
        }
    }
}
