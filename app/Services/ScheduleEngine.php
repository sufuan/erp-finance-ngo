<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;

/**
 * Step 16/17 — the loan schedule engine (binding formulas).
 *
 * FLAT:      profit = amount × rate% × term-in-years; term from frequency
 *            (daily n/365, weekly n/52, monthly n/12, quarterly n/4,
 *            half-yearly n/2, yearly n, 15-days n/24). Equal splits.
 * DECLINING: PMT amortization — period rate r = rate% / periods-per-year,
 *            installment = P·r / (1 − (1+r)^−n); each period's profit is
 *            outstanding × r and the final row absorbs rounding.
 *
 * All math is brick/math BigDecimal, scale 2, HALF_UP — never floats.
 * `preview()` is the read-only calculation shared by the proposal form
 * (Step 16) and the opening form (Step 17, where generate() also writes
 * loan_schedules rows with working-day shifting).
 */
class ScheduleEngine
{
    /** Installments per year by frequency code. */
    public const PERIODS_PER_YEAR = [
        'D' => 365, 'W' => 52, 'M' => 12, 'Q' => 4, 'H' => 2, 'Y' => 1,
        'F-15days' => 24, 'AtMaturity' => 1, 'S-single' => 1, 'N-na' => 1,
    ];

    public function periodsPerYear(string $frequency): int
    {
        return self::PERIODS_PER_YEAR[$frequency] ?? 12;
    }

    /** Step a date by one installment period of the frequency. */
    public function stepDate(Carbon $date, string $frequency): Carbon
    {
        return match ($frequency) {
            'D' => $date->copy()->addDay(),
            'W' => $date->copy()->addWeek(),
            'M' => $date->copy()->addMonth(),
            'Q' => $date->copy()->addMonths(3),
            'H' => $date->copy()->addMonths(6),
            'Y' => $date->copy()->addYear(),
            'F-15days' => $date->copy()->addDays(15),
            default => $date->copy()->addMonth(),
        };
    }

    /**
     * @return array{installment_amount: string, actual_installment_amount: string, extra_installment_amount: string, last_installment_amount: string, profit_amount: string, total_repayment: string, installment_expire_date: string}
     */
    public function preview(string $amount, string $ratePercent, string $rateMethod, string $frequency, int $installments, string $fromDate): array
    {
        $principal = BigDecimal::of($amount);
        $ppy = $this->periodsPerYear($frequency);
        if ($rateMethod === 'flat') {
            $termYears = BigDecimal::of($installments)->dividedBy($ppy, 10, RoundingMode::HalfUp);
            $profit = $principal->multipliedBy($ratePercent)->multipliedBy($termYears)
                ->dividedBy(100, 10, RoundingMode::HalfUp)->toScale(2, RoundingMode::HalfUp);
            $total = $principal->plus($profit);
            $installment = $total->dividedBy($installments, 2, RoundingMode::HalfUp);
            $last = $total->minus($installment->multipliedBy($installments - 1));
        } else {
            $r = BigDecimal::of($ratePercent)->dividedBy(100 * $ppy, 10, RoundingMode::HalfUp);
            if ($r->isZero()) {
                $installment = $principal->dividedBy($installments, 2, RoundingMode::HalfUp);
                $profit = BigDecimal::zero();
                $total = $principal;
                $last = $total->minus($installment->multipliedBy($installments - 1));
            } else {
                // PMT = P·r / (1 − (1+r)^−n); the power is built with a
                // scale-trimming loop so long daily terms stay cheap.
                $base = BigDecimal::one()->plus($r);
                $factor = BigDecimal::one();
                for ($i = 0; $i < $installments; $i++) {
                    $factor = $factor->multipliedBy($base)->toScale(12, RoundingMode::HalfUp);
                }
                $inverse = BigDecimal::one()->dividedBy($factor, 10, RoundingMode::HalfUp);
                $pmt = $principal->multipliedBy($r)
                    ->dividedBy(BigDecimal::one()->minus($inverse), 10, RoundingMode::HalfUp)
                    ->toScale(2, RoundingMode::HalfUp);
                $installment = $pmt;
                $total = $pmt->multipliedBy($installments);
                $profit = $total->minus($principal);
                $last = $pmt;
            }
        }
        $expiry = Carbon::parse($fromDate);
        for ($i = 1; $i < $installments; $i++) {
            $expiry = $this->stepDate($expiry, $frequency);
        }
        // (expiry here is the raw stepped date; generate() applies the
        // working-day shift when writing real schedule rows.)

        return [
            'installment_amount' => (string) $installment->toScale(2, RoundingMode::HalfUp),
            'actual_installment_amount' => (string) $installment->toScale(2, RoundingMode::HalfUp),
            'extra_installment_amount' => '0.00',
            'last_installment_amount' => (string) $last->toScale(2, RoundingMode::HalfUp),
            'profit_amount' => (string) $profit->toScale(2, RoundingMode::HalfUp),
            'total_repayment' => (string) $total->toScale(2, RoundingMode::HalfUp),
            'installment_expire_date' => $expiry->toDateString(),
        ];
    }

    /** Bangladesh weekend: Friday + Saturday. */
    public function isWeekend(Carbon $date): bool
    {
        return $date->isFriday() || $date->isSaturday();
    }

    /**
     * Shift a due date forward until it lands on a working day: not a
     * Fri/Sat weekend and not a holiday (branch/samity scoped rows in the
     * `holidays` table).
     */
    public function shiftToWorkingDay(Carbon $date, ?int $branchId = null, ?int $samityId = null): Carbon
    {
        $cursor = $date->copy();
        for ($guard = 0; $guard < 370; $guard++) {
            if (! $this->isWeekend($cursor) && ! $this->isHoliday($cursor, $branchId, $samityId)) {
                return $cursor;
            }
            $cursor->addDay();
        }

        return $cursor;
    }

    private function isHoliday(Carbon $date, ?int $branchId, ?int $samityId): bool
    {
        return \App\Models\Holiday::where('holiday_date', $date->toDateString())
            ->where(function ($q) use ($branchId, $samityId) {
                $q->whereNull('branch_id')->whereNull('samity_id');
                if ($branchId) {
                    $q->orWhere('branch_id', $branchId);
                }
                if ($samityId) {
                    $q->orWhere('samity_id', $samityId);
                }
            })->exists();
    }

    /**
     * Build the full split rows for a loan (Step 17 generate()). Due dates
     * step from the first installment date and shift to working days;
     * weekly loans first align to the samity's meeting day when given.
     * The final row absorbs all rounding remainders.
     *
     * @return array<int, array{installment_no: int, due_date: string, principal_due: string, profit_due: string, total_due: string}>
     */
    public function rows(string $amount, string $ratePercent, string $rateMethod, string $frequency, int $installments, string $fromDate, ?int $branchId = null, ?int $samityId = null, ?string $samityDay = null): array
    {
        $principal = BigDecimal::of($amount);
        $ppy = $this->periodsPerYear($frequency);
        $first = Carbon::parse($fromDate);
        if ($frequency === 'W' && $samityDay) {
            // Weekly installments fall on the samity's meeting day.
            for ($guard = 0; $guard < 7 && $first->format('l') !== $samityDay; $guard++) {
                $first->addDay();
            }
        }
        $dates = [];
        $cursor = $first->copy();
        for ($i = 0; $i < $installments; $i++) {
            $dates[] = $this->shiftToWorkingDay($cursor, $branchId, $samityId)->toDateString();
            $cursor = $this->stepDate($cursor, $frequency);
        }

        $rows = [];
        if ($rateMethod === 'flat') {
            $preview = $this->preview($amount, $ratePercent, $rateMethod, $frequency, $installments, $fromDate);
            $totalProfit = BigDecimal::of($preview['profit_amount']);
            $perPrincipal = $principal->dividedBy($installments, 2, RoundingMode::HalfUp);
            $perProfit = $totalProfit->dividedBy($installments, 2, RoundingMode::HalfUp);
            $principalLeft = $principal;
            $profitLeft = $totalProfit;
            for ($i = 1; $i <= $installments; $i++) {
                $isLast = $i === $installments;
                $p = $isLast ? $principalLeft : $perPrincipal;
                $f = $isLast ? $profitLeft : $perProfit;
                $principalLeft = $principalLeft->minus($p);
                $profitLeft = $profitLeft->minus($f);
                $rows[] = [
                    'installment_no' => $i, 'due_date' => $dates[$i - 1],
                    'principal_due' => (string) $p->toScale(2, RoundingMode::HalfUp),
                    'profit_due' => (string) $f->toScale(2, RoundingMode::HalfUp),
                    'total_due' => (string) $p->plus($f)->toScale(2, RoundingMode::HalfUp),
                ];
            }

            return $rows;
        }

        // Declining amortization.
        $preview = $this->preview($amount, $ratePercent, $rateMethod, $frequency, $installments, $fromDate);
        $installment = BigDecimal::of($preview['installment_amount']);
        $r = BigDecimal::of($ratePercent)->dividedBy(100 * $ppy, 10, RoundingMode::HalfUp);
        $outstanding = $principal;
        for ($i = 1; $i <= $installments; $i++) {
            $profit = $outstanding->multipliedBy($r)->toScale(2, RoundingMode::HalfUp);
            if ($i === $installments) {
                $principalPart = $outstanding;
                $total = $principalPart->plus($profit);
            } else {
                $principalPart = $installment->minus($profit);
                $total = $installment;
            }
            $outstanding = $outstanding->minus($principalPart);
            $rows[] = [
                'installment_no' => $i, 'due_date' => $dates[$i - 1],
                'principal_due' => (string) $principalPart->toScale(2, RoundingMode::HalfUp),
                'profit_due' => (string) $profit->toScale(2, RoundingMode::HalfUp),
                'total_due' => (string) $total->toScale(2, RoundingMode::HalfUp),
            ];
        }

        return $rows;
    }
}
