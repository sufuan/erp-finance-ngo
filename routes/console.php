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
