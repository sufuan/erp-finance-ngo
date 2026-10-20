<?php

namespace App\Services;

use App\Models\AccountTransaction;
use App\Models\CashLedger;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Nightly reconciliation (plan Step 3A): cached balances vs the canonical
 * sub-ledger, and member sub-ledger totals vs GL control ledgers.
 * Returns a list of mismatches (empty = clean); the command audits them.
 */
class ReconcileService
{
    public function __construct(private readonly PostingService $posting) {}

    /** @return array<int, array{check:string, expected:string, actual:string}> */
    public function run(): array
    {
        $mismatches = [];

        // 1) Cash: cached cash_ledgers vs sub-ledger sums (excluding cancelled).
        foreach (CashLedger::all() as $cash) {
            $sum = AccountTransaction::where('account_type', 'cash')->where('account_id', $cash->user_id)
                ->whereNull('cancel_at')->sum('amount');
            if (! BigDecimal::of((string) $sum)->isEqualTo(BigDecimal::of($cash->current_balance))) {
                $mismatches[] = ['check' => "cash user {$cash->user_id}", 'expected' => (string) $sum, 'actual' => (string) $cash->current_balance];
            }
        }

        // 2) Control ledgers: GL balance (derived) vs sub-ledger totals.
        $controlChecks = [
            // ledger code => [account_type, sign: sub-ledger total should equal +GL or -GL]
            '2010' => 'deposit', '2020' => 'deposit', '2030' => 'deposit', // deposit controls are per-type accounts; checked in aggregate below
            '1100' => 'loan',
            '3010' => 'share',
            '1010' => 'cash',
        ];
        foreach ($controlChecks as $code => $type) {
            $ledger = \App\Models\Ledger::byCode($code);
            if (! $ledger) {
                continue;
            }
            $gl = $this->posting->ledgerBalance($ledger);
            $sub = AccountTransaction::where('account_type', $type)->whereNull('cancel_at')->sum('amount');
            // Cash/deposit/loan/share sub-ledgers track holder balances; GL
            // control accounts mirror them (deposit types share one pool,
            // so compare deposit controls in aggregate).
            if ($type === 'deposit') {
                continue; // handled by the aggregate check below
            }
            if ($type === 'cash') {
                // Cash-in-hand GL includes all users' cash.
                if (! BigDecimal::of($gl)->isEqualTo(BigDecimal::of((string) $sub))) {
                    $mismatches[] = ['check' => "GL {$code} vs {$type} sub-ledger", 'expected' => (string) $sub, 'actual' => $gl];
                }
            }
            if ($type === 'loan') {
                // Loan sub-ledger amount sign: disbursement is negative from
                // the member's perspective in later steps; at 3A only check
                // absolute consistency when rows exist.
                $count = AccountTransaction::where('account_type', 'loan')->count();
                if ($count > 0 && ! BigDecimal::of($gl)->abs()->isEqualTo(BigDecimal::of((string) $sub)->abs())) {
                    $mismatches[] = ['check' => "GL {$code} vs {$type} sub-ledger", 'expected' => (string) $sub, 'actual' => $gl];
                }
            }
            if ($type === 'share' && ! BigDecimal::of($gl)->isEqualTo(BigDecimal::of((string) $sub))) {
                $mismatches[] = ['check' => "GL {$code} vs {$type} sub-ledger", 'expected' => (string) $sub, 'actual' => $gl];
            }
        }

        // 3) Deposit controls in aggregate (savings + DPS + FDR controls vs
        //    all deposit sub-ledger rows — accounts get type tags in Step 10;
        //    at 3A this compares only when deposit rows exist).
        $depositRows = AccountTransaction::where('account_type', 'deposit')->whereNull('cancel_at')->count();
        if ($depositRows > 0) {
            $glTotal = BigDecimal::zero();
            foreach (['2010', '2020', '2030'] as $code) {
                $glTotal = $glTotal->plus($this->posting->ledgerBalance(\App\Models\Ledger::byCode($code)));
            }
            $subTotal = AccountTransaction::where('account_type', 'deposit')->whereNull('cancel_at')->sum('amount');
            if (! $glTotal->isEqualTo(BigDecimal::of((string) $subTotal))) {
                $mismatches[] = ['check' => 'GL deposit controls vs deposit sub-ledger', 'expected' => (string) $subTotal, 'actual' => (string) $glTotal];
            }
        }

        // 4) Trial balance per branch.
        foreach (\App\Models\Branch::pluck('id') as $branchId) {
            $tb = $this->posting->trialBalance($branchId);
            if (! $tb['balanced']) {
                $mismatches[] = ['check' => "trial balance branch {$branchId}", 'expected' => $tb['debit'], 'actual' => $tb['credit']];
            }
        }

        return $mismatches;
    }
}
