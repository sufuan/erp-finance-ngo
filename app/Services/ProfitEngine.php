<?php

namespace App\Services;

use App\Models\DailyBalance;
use App\Models\DepositAccount;
use App\Models\ProfitSetup;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;

/**
 * Step 13 — binding profit formulas (rate r is a percent, days d = the
 * inclusive day count of the period):
 *   D Daily:      Σ over days (daily_balance × r/100/365)
 *   B As-on-day:  balance on to_date × r/100 × d/365
 *   M Minimum:    min daily balance × r/100 × d/365
 *   X Maximum:    max daily balance × r/100 × d/365
 *   A Average:    avg daily balance × r/100 × d/365
 *   O Opening:    opening balance × r/100 × d/365
 *   N N/A:        0
 * Daily/min/max/average math reads `daily_balances` ONLY (the Step 27 EOD
 * snapshot) — never raw transactions. B/O fall back to the account's
 * current cached balance when no snapshot exists yet. All money math is
 * brick/math, scale 2, HALF_UP.
 */
class ProfitEngine
{
    public const BASES = ['D', 'B', 'M', 'X', 'A', 'O', 'N'];

    public function daysInPeriod(string $from, string $to): int
    {
        return Carbon::parse($from)->startOfDay()->diffInDays(Carbon::parse($to)->startOfDay()) + 1;
    }

    /** Latest active per-account setup rate effective on/before the date. */
    public function rateFor(int $depositAccountId, string $onDate): ?string
    {
        $setup = ProfitSetup::where('deposit_account_id', $depositAccountId)
            ->whereNull('cancelled_at')->where('effective_date', '<=', $onDate)
            ->orderByDesc('effective_date')->orderByDesc('serial')->first();

        return $setup ? (string) $setup->profit_rate : null;
    }

    /** @return array<int, BigDecimal> balance_date (Y-m-d) => balance */
    private function snapshots(DepositAccount $account, string $from, string $to): array
    {
        $rows = DailyBalance::where('account_type', 'deposit')->where('account_id', $account->id)
            ->whereBetween('balance_date', [$from, $to])->orderBy('balance_date')->get();
        $out = [];
        foreach ($rows as $row) {
            $out[$row->balance_date->toDateString()] = BigDecimal::of((string) $row->balance);
        }

        return $out;
    }

    /**
     * @return array{balance: string, profit: string} balance = the figure the
     * profit was computed on (average for D/A, min for M, max for X,
     * as-on for B, opening for O)
     */
    public function compute(DepositAccount $account, string $basis, string $ratePercent, string $from, string $to): array
    {
        $rate = BigDecimal::of($ratePercent);
        $days = $this->daysInPeriod($from, $to);
        $snaps = $this->snapshots($account, $from, $to);
        $zero = ['balance' => '0.00', 'profit' => '0.00'];
        if ($basis === 'N') {
            return $zero;
        }

        if ($basis === 'D') {
            if ($snaps === []) {
                return $zero;
            }
            $sum = BigDecimal::zero();
            $balanceSum = BigDecimal::zero();
            foreach ($snaps as $balance) {
                $sum = $sum->plus($balance->multipliedBy($rate)->dividedBy(36500, 10, RoundingMode::HalfUp));
                $balanceSum = $balanceSum->plus($balance);
            }
            $average = $balanceSum->dividedBy(count($snaps), 10, RoundingMode::HalfUp);

            return [
                'balance' => (string) $average->toScale(2, RoundingMode::HalfUp),
                'profit' => (string) $sum->toScale(2, RoundingMode::HalfUp),
            ];
        }

        $figure = match ($basis) {
            'B' => $this->asOnFigure($account, $snaps, $to),
            'O' => $this->openingFigure($account, $snaps),
            'M' => $snaps === [] ? null : collect($snaps)->reduce(fn ($min, $b) => $min === null || $b->isLessThan($min) ? $b : $min),
            'X' => $snaps === [] ? null : collect($snaps)->reduce(fn ($max, $b) => $max === null || $b->isGreaterThan($max) ? $b : $max),
            'A' => $snaps === [] ? null : collect($snaps)->reduce(fn ($s, $b) => $s->plus($b), BigDecimal::zero())
                ->dividedBy(count($snaps), 10, RoundingMode::HalfUp),
            default => null,
        };
        if ($figure === null) {
            return $zero;
        }
        $profit = $figure->multipliedBy($rate)->multipliedBy($days)
            ->dividedBy(36500, 10, RoundingMode::HalfUp);

        return [
            'balance' => (string) $figure->toScale(2, RoundingMode::HalfUp),
            'profit' => (string) $profit->toScale(2, RoundingMode::HalfUp),
        ];
    }

    /** @param array<int, BigDecimal> $snaps */
    private function asOnFigure(DepositAccount $account, array $snaps, string $to): BigDecimal
    {
        if (isset($snaps[$to])) {
            return $snaps[$to];
        }
        if ($snaps !== []) {
            return end($snaps);
        }
        $before = DailyBalance::where('account_type', 'deposit')->where('account_id', $account->id)
            ->where('balance_date', '<=', $to)->orderByDesc('balance_date')->first();

        return $before ? BigDecimal::of((string) $before->balance) : BigDecimal::of((string) $account->current_balance);
    }

    /** @param array<int, BigDecimal> $snaps */
    private function openingFigure(DepositAccount $account, array $snaps): BigDecimal
    {
        if ($snaps !== []) {
            return reset($snaps);
        }

        return BigDecimal::of((string) $account->current_balance);
    }
}
