<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/**
 * Step 15 — the MRA service-charge cap. The cap in
 * `settings.loan.max_service_charge_rate` (24%) applies to the product's
 * NOMINAL profit rate for BOTH rate methods (client rule, 2026-11-14):
 * flat profit is charged once per loan cycle on the principal (see
 * ScheduleEngine), not annualized, so there is no IRR/effective
 * conversion — the rate on the product is the rate that is capped.
 */
class LoanRateGuard
{
    public function __construct(private SettingsService $settings) {}

    /** The rate the cap is checked against: the nominal product rate. */
    public function effectiveRatePercent(string $ratePercent, string $rateMethod): float
    {
        return (float) $ratePercent;
    }

    /** @throws ValidationException when the product rate breaks the cap */
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
