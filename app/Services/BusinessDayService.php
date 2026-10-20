<?php

namespace App\Services;

use App\Models\BusinessDay;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Business-day engine (plan Step 1). Each branch has its own open business
 * date; closing a day locks it — postings to a closed/prior date are
 * rejected (offline sync rollover rules live in Step 27/42).
 */
class BusinessDayService
{
    /**
     * The branch's current OPEN business day, creating today's row on
     * first use (branch opening).
     */
    public function current(int $branchId): BusinessDay
    {
        $open = BusinessDay::where('branch_id', $branchId)
            ->where('is_closed', false)
            ->orderByDesc('business_date')
            ->first();

        if ($open) {
            return $open;
        }

        // No open day: only auto-create when today has no row at all. If
        // today's row exists but is closed, the branch is in a bad state —
        // never resurrect a closed date (and never duplicate the row).
        $today = CarbonImmutable::now('Asia/Dhaka')->toDateString();
        if (BusinessDay::where('branch_id', $branchId)->where('business_date', $today)->exists()) {
            throw new DomainException("Business date {$today} is closed for branch {$branchId}.");
        }

        return BusinessDay::create([
            'branch_id' => $branchId,
            'business_date' => $today,
            'is_closed' => false,
        ]);
    }

    public function currentDate(int $branchId): string
    {
        return $this->current($branchId)->business_date->toDateString();
    }

    /**
     * Start Closing: mark the current day closed and open the next
     * calendar day. Returns the newly opened day.
     */
    public function close(int $branchId, ?int $userId = null): BusinessDay
    {
        return DB::transaction(function () use ($branchId, $userId) {
            $current = BusinessDay::where('branch_id', $branchId)
                ->where('is_closed', false)
                ->orderByDesc('business_date')
                ->lockForUpdate()
                ->first();

            if (! $current) {
                $current = $this->current($branchId);
                $current = BusinessDay::where('id', $current->id)->lockForUpdate()->first();
            }

            $current->update([
                'is_closed' => true,
                'closed_by' => $userId,
                'closed_at' => now(),
                'updated_by' => $userId,
            ]);

            $nextDate = $current->business_date->copy()->addDay();

            return BusinessDay::firstOrCreate(
                ['branch_id' => $branchId, 'business_date' => $nextDate->toDateString()],
                ['is_closed' => false, 'created_by' => $userId]
            );
        });
    }

    /**
     * Guard used by every posting path: the given date must be the
     * branch's current open business date.
     */
    public function assertOpen(int $branchId, string $date): void
    {
        $current = $this->currentDate($branchId);
        if ($date !== $current) {
            throw new DomainException(
                "Business date {$date} is not open for branch {$branchId} (current open date: {$current})."
            );
        }
    }

    public function isClosed(int $branchId, string $date): bool
    {
        return BusinessDay::where('branch_id', $branchId)
            ->where('business_date', $date)
            ->where('is_closed', true)
            ->exists();
    }
}
