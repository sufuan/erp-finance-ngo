<?php

namespace App\Services;

use App\Models\DepositAccount;
use App\Models\DepositTransaction;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;

/**
 * Long Term Savings profit per the client's printed terms
 * (leaflet, confirmed 2026-11-18). The figures live in the LTS
 * product's `config` (admin-editable): `payout_table` = maturity
 * profit (taka) by daily deposit amount × term years, and
 * `premature_slabs` = profit % on the total deposited by completed
 * years when closing early.
 *
 * Maturity: profit = table[daily amount][term] scaled pro-rata by
 * (actual collected installment days ÷ (260 × term years)), the
 * ratio capped at 1 — a member who collected every scheduled day
 * earns the full printed profit; missed days reduce it in
 * proportion. Collected days = total received ÷ the daily amount.
 *
 * Premature: profit = slab % × total deposited, the slab chosen by
 * completed years since opening (<1y 0%, 1y 7%, 2y 9%, 3y 11%,
 * 4y+ 13% per the printed terms).
 *
 * Applies only to accounts whose product carries a payout table
 * (the LTS product) with a DAILY collection rhythm — the printed
 * table is defined on daily amounts; other products and rhythms
 * keep the existing manual profit behaviour (compute() → null).
 */
class LtsProfitService
{
    public function isLts(DepositAccount $account): bool
    {
        $account->loadMissing('product');
        $config = $account->product?->config;

        return is_array($config) && ! empty($config['payout_table']);
    }

    /**
     * @return array{profit: string, basis: string, term_years: int, deposited: string, collected_days: string, expected_days: int|null, slab_rate: string|null}|null
     */
    public function compute(DepositAccount $account, string $closingDate): ?array
    {
        if (! $this->isLts($account)) {
            return null;
        }
        $config = $account->product->config;
        $daily = BigDecimal::of((string) ($account->installment_amount ?? 0));
        if (! $daily->isPositive()) {
            return null;
        }
        $dailyKey = (string) $daily->toScale(0, RoundingMode::HalfUp);
        $table = collect($config['payout_table'] ?? [])
            ->mapWithKeys(fn ($row, $amount) => [(string) $amount => collect($row)->mapWithKeys(fn ($v, $y) => [(string) $y => $v])->all()])
            ->all();
        if (! isset($table[$dailyKey])) {
            return null;
        }

        $deposited = BigDecimal::of((string) DepositTransaction::where('account_id', $account->id)
            ->where('tran_type', 'Receive')->whereNull('cancel_at')->sum('amount'));
        $collectedDays = $deposited->dividedBy($daily, 4, RoundingMode::HalfUp);
        $opening = Carbon::parse($account->opening_date);
        $closing = Carbon::parse($closingDate);

        $termYears = null;
        if ($account->maturity_date) {
            $months = (int) round($opening->diffInMonths($account->maturity_date));
            $termYears = (int) round($months / 12);
        }

        if ($account->maturity_date && $termYears && isset($table[$dailyKey][(string) $termYears])
            && ! $closing->lt($account->maturity_date)) {
            $daysPerYear = (int) ($config['collection_days_per_year'] ?? 260);
            $expectedDays = $daysPerYear * $termYears;
            $ratio = $collectedDays->dividedBy($expectedDays, 6, RoundingMode::HalfUp);
            if ($ratio->isGreaterThan(1)) {
                $ratio = BigDecimal::one();
            }
            $profit = BigDecimal::of((string) $table[$dailyKey][(string) $termYears])
                ->multipliedBy($ratio)->toScale(2, RoundingMode::HalfUp);

            return [
                'profit' => (string) $profit,
                'basis' => 'maturity',
                'term_years' => $termYears,
                'deposited' => (string) $deposited->toScale(2, RoundingMode::HalfUp),
                'collected_days' => (string) $collectedDays->toScale(2, RoundingMode::HalfUp),
                'expected_days' => $expectedDays,
                'slab_rate' => null,
            ];
        }

        // Premature: slab % on the total deposited by completed years.
        $completedYears = (int) $opening->diffInYears($closing);
        $rate = BigDecimal::zero();
        foreach (($config['premature_slabs'] ?? []) as $slab) {
            $min = (int) ($slab['min_years'] ?? 0);
            $max = $slab['max_years'] ?? null;
            if ($completedYears >= $min && ($max === null || $completedYears < (int) $max)) {
                $rate = BigDecimal::of((string) ($slab['rate'] ?? 0));
                break;
            }
        }
        $profit = $deposited->multipliedBy($rate)->dividedBy(100, 2, RoundingMode::HalfUp);

        return [
            'profit' => (string) $profit,
            'basis' => 'premature',
            'term_years' => $termYears ?? $completedYears,
            'deposited' => (string) $deposited->toScale(2, RoundingMode::HalfUp),
            'collected_days' => (string) $collectedDays->toScale(2, RoundingMode::HalfUp),
            'expected_days' => null,
            'slab_rate' => (string) $rate->toScale(2, RoundingMode::HalfUp),
        ];
    }
}
