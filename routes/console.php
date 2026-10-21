<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Nightly database backup to object storage (plan Step 1).
\Illuminate\Support\Facades\Schedule::command('db:backup')->dailyAt('02:00');
// Step 3A: nightly accounting reconciliation (mismatches -> audit_logs).
Schedule::command('accounts:reconcile')->dailyAt('02:30');
// Auto business-day closing (owner feature): nightly at 23:59 Asia/Dhaka
// (app timezone is UTC, so the timezone is pinned here). The command
// itself no-ops unless the `business_day.auto_close` setting is on.
\Illuminate\Support\Facades\Schedule::command('business-day:auto-close')->dailyAt('23:59')->timezone('Asia/Dhaka');
