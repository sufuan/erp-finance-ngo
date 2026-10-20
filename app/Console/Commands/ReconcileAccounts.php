<?php

namespace App\Console\Commands;

use App\Services\AuditService;
use App\Services\ReconcileService;
use Illuminate\Console\Command;

class ReconcileAccounts extends Command
{
    protected $signature = 'accounts:reconcile';

    protected $description = 'Nightly reconciliation: cached balances vs sub-ledger, sub-ledger vs GL controls';

    public function handle(ReconcileService $reconcile, AuditService $audit): int
    {
        $mismatches = $reconcile->run();

        if ($mismatches === []) {
            $this->info('Reconciliation clean: all balances agree.');
            return self::SUCCESS;
        }

        foreach ($mismatches as $m) {
            $this->error("Mismatch [{$m['check']}]: expected {$m['expected']}, actual {$m['actual']}");
            $audit->log(null, 'accounts.reconcile_mismatch', 'account_transactions', null, null, $m);
        }

        return self::FAILURE;
    }
}
