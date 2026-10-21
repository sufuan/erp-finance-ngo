<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\BusinessDay;
use App\Services\AuditService;
use App\Services\BusinessDayService;
use App\Services\SettingsService;
use Illuminate\Console\Command;

/**
 * Nightly auto closing of the business day (owner feature). Runs from
 * the scheduler at 23:59 Asia/Dhaka; a no-op unless the admin turned
 * the Auto Closing toggle on (settings key `business_day.auto_close`,
 * default off — closing then stays manual, exactly as before).
 *
 * Same semantics as the manual Start Closing button: each branch with
 * an open day is advanced by exactly one day via BusinessDayService —
 * no multi-day catch-up if the scheduler missed a night.
 */
class AutoCloseBusinessDay extends Command
{
    protected $signature = 'business-day:auto-close';

    protected $description = 'Close every branch\'s business day automatically (only when the Auto Closing setting is on)';

    public function handle(SettingsService $settings, BusinessDayService $days, AuditService $audit): int
    {
        if (! $settings->get('business_day.auto_close', false)) {
            $this->info('Auto closing is off — business days stay manual.');

            return self::SUCCESS;
        }

        $closed = 0;
        foreach (Branch::query()->orderBy('id')->get() as $branch) {
            // Only branches that actually have an open day — never call
            // BusinessDayService::current() here: it would create a day
            // row for a branch that never opened one, then close it.
            $hasOpenDay = BusinessDay::where('branch_id', $branch->id)
                ->where('is_closed', false)
                ->exists();
            if (! $hasOpenDay) {
                continue;
            }

            $before = $days->currentDate($branch->id);
            $newDay = $days->close($branch->id, null);
            $audit->log(null, 'business_day.auto_closed', 'branches', $branch->id,
                ['business_date' => $before], ['business_date' => $newDay->business_date->toDateString()]);
            $closed++;
        }

        $this->info("Auto-closed the business day for {$closed} branch(es).");

        return self::SUCCESS;
    }
}
