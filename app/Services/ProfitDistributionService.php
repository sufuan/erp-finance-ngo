<?php

namespace App\Services;

use App\Models\DepositAccount;
use App\Models\GlMapping;
use App\Models\ProfitDistribution;
use App\Models\ProfitDistributionLine;
use App\Models\ProfitPayment;
use App\Models\Samity;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Step 13 — distribution runs: calculate lines with ProfitEngine, post them
 * through PostingService (deposit.profit.distribute; TDS withheld at the
 * `profit.tax_withholding_rate` setting via deposit.profit.tds when > 0),
 * and pay distributed profit out in cash (deposit.profit.pay) against the
 * lines' profit_pending, oldest distribution first.
 */
class ProfitDistributionService
{
    public function __construct(
        private ProfitEngine $engine,
        private PostingService $posting,
        private BusinessDayService $businessDays,
        private SettingsService $settings,
        private DepositTransactionService $transactions,
    ) {}

    /**
     * @param  array{samity_id: int, account_type: string, profit_basis: string, from_date: string, to_date: string, profit_rate: string, posting_date: string, exclude_zero_balance: bool, auto_rates: bool}  $input
     */
    public function calculate(array $input, User $actor): ProfitDistribution
    {
        $samity = Samity::findOrFail($input['samity_id']);
        $accounts = DepositAccount::with(['product', 'member'])
            ->where('is_active', true)->whereNull('closing_date')
            ->whereHas('member', fn ($q) => $q->where('samity_id', $samity->id))
            ->when($input['account_type'] !== 'ALL', fn ($q) => $q->whereHas(
                'product', fn ($p) => $p->where('code', $input['account_type'])
            ))
            ->orderBy('id')->get();

        return DB::transaction(function () use ($input, $actor, $samity, $accounts) {
            $distribution = ProfitDistribution::create([
                'branch_id' => $samity->branch_id, 'samity_id' => $samity->id,
                'account_type' => $input['account_type'],
                'from_date' => $input['from_date'], 'to_date' => $input['to_date'],
                'profit_basis' => $input['profit_basis'],
                'profit_rate' => $input['auto_rates'] ? 0 : $input['profit_rate'],
                'distribution_amount' => 0, 'posting_date' => $input['posting_date'],
                'exclude_zero_balance' => $input['exclude_zero_balance'],
                'status' => 'Calculated',
            ]);
            $total = BigDecimal::zero();
            foreach ($accounts as $account) {
                // Manual runs use the operator's rate for every account.
                // Automatic runs resolve per account: latest profit setup,
                // falling back to the product default rate.
                $rate = $input['auto_rates']
                    ? ($this->engine->rateFor($account->id, $input['to_date'])
                        ?? (string) $account->product->default_profit_rate)
                    : $input['profit_rate'];
                $result = $this->engine->compute($account, $input['profit_basis'], (string) $rate, $input['from_date'], $input['to_date']);
                if ($input['exclude_zero_balance'] && BigDecimal::of($result['balance'])->isZero()) {
                    continue;
                }
                ProfitDistributionLine::create([
                    'distribution_id' => $distribution->id, 'account_type' => 'deposit',
                    'account_id' => $account->id, 'balance' => $result['balance'],
                    'profit_on_amount' => $result['balance'],
                    'profit_pending' => $result['profit'], 'total_payable' => $result['profit'],
                ]);
                $total = $total->plus($result['profit']);
            }
            $distribution->update(['distribution_amount' => (string) $total->toScale(2, RoundingMode::HalfUp)]);

            return $distribution->fresh();
        });
    }

    public function post(ProfitDistribution $distribution, User $actor): ProfitDistribution
    {
        return DB::transaction(function () use ($distribution, $actor) {
            $distribution = ProfitDistribution::with('lines.account.product')->lockForUpdate()->findOrFail($distribution->id);
            if ($distribution->status === 'Posted') {
                throw ValidationException::withMessages(['status' => __('distribution_already_posted')]);
            }
            $taxRate = BigDecimal::of((string) ($this->settings->get('profit.tax_withholding_rate', 0) ?? 0));
            $lines = [];
            $movements = [];
            $accounts = [];
            foreach ($distribution->lines as $line) {
                $payable = BigDecimal::of((string) $line->total_payable);
                if ($payable->isZero()) {
                    continue;
                }
                $account = $line->account;
                $accounts[$account->id] = $account;
                $tax = $payable->multipliedBy($taxRate)->dividedBy(100, 10, RoundingMode::HalfUp)
                    ->toScale(2, RoundingMode::HalfUp);
                $net = $payable->minus($tax);
                $profitMapping = GlMapping::byKey($this->profitMappingKey($account));
                $lines[] = ['ledger_id' => $profitMapping->debit_ledger_id, 'debit' => (string) $payable, 'credit' => '0.00', 'deposit_account_id' => $account->id];
                $lines[] = ['ledger_id' => $profitMapping->credit_ledger_id, 'debit' => '0.00', 'credit' => (string) $net, 'deposit_account_id' => $account->id];
                if (! $tax->isZero()) {
                    $tdsMapping = GlMapping::byKey('deposit.profit.tds');
                    $lines[] = ['ledger_id' => $tdsMapping->credit_ledger_id, 'debit' => '0.00', 'credit' => (string) $tax, 'deposit_account_id' => $account->id];
                }
                $movements[] = ['account_type' => 'deposit', 'account_id' => $account->id, 'tran_code' => 'PROFIT', 'amount' => (string) $net, 'ref_type' => 'profit_distribution', 'ref_id' => $distribution->id];
            }
            if ($lines !== []) {
                $this->posting->post([
                    'branch_id' => $distribution->branch_id, 'voucher_type' => 'Journal',
                    'entry_by' => $actor->id,
                    'transaction_date' => $this->businessDays->currentDate($distribution->branch_id),
                    'comments' => 'Profit distribution #'.$distribution->id,
                    'lines' => $lines, 'movements' => $movements,
                ]);
                foreach ($accounts as $account) {
                    $this->transactions->resyncCache($account);
                }
            }
            $distribution->update(['status' => 'Posted', 'posted_by' => $actor->id, 'posted_at' => now()]);

            return $distribution->fresh();
        });
    }

    /** Total distributed-but-unpaid profit for an account. */
    public function pendingFor(DepositAccount $account): string
    {
        $sum = ProfitDistributionLine::where('account_id', $account->id)
            ->whereHas('distribution', fn ($q) => $q->where('status', 'Posted'))
            ->sum('profit_pending');

        return (string) BigDecimal::of((string) $sum)->toScale(2, RoundingMode::HalfUp);
    }

    /** Total profit already paid out for an account. */
    public function paidFor(DepositAccount $account): string
    {
        return (string) BigDecimal::of((string) ProfitPayment::where('deposit_account_id', $account->id)->sum('profit_amount'))
            ->toScale(2, RoundingMode::HalfUp);
    }

    public function payProfit(DepositAccount $account, string $amount, string $paymentDate, ?string $comments, User $actor): ProfitPayment
    {
        return DB::transaction(function () use ($account, $amount, $paymentDate, $comments, $actor) {
            $account = DepositAccount::lockForUpdate()->findOrFail($account->id);
            $pending = BigDecimal::of($this->pendingFor($account));
            $pay = BigDecimal::of($amount);
            if ($pay->isGreaterThan($pending)) {
                throw ValidationException::withMessages(['profit_amount' => __('profit_exceeds_pending')]);
            }
            if ($pay->isGreaterThan(BigDecimal::of((string) $account->current_balance))) {
                throw ValidationException::withMessages(['profit_amount' => __('insufficient_balance')]);
            }
            $payment = ProfitPayment::create([
                'deposit_account_id' => $account->id, 'profit_amount' => $amount,
                'payment_date' => $paymentDate, 'comments' => $comments,
                'paid_by' => $actor->id, 'paid_at' => now(),
            ]);
            $this->posting->post([
                'branch_id' => $account->branch_id, 'voucher_type' => 'Payment', 'entry_by' => $actor->id,
                'transaction_date' => $this->businessDays->currentDate($account->branch_id),
                'comments' => $comments,
                'lines' => [['mapping_key' => 'deposit.profit.pay', 'amount' => $amount, 'deposit_account_id' => $account->id]],
                'movements' => [
                    ['account_type' => 'deposit', 'account_id' => $account->id, 'tran_code' => 'PROFIT_PAYMENT', 'amount' => '-'.$amount, 'ref_type' => 'profit_payment', 'ref_id' => $payment->id],
                    ['account_type' => 'cash', 'account_id' => $actor->id, 'tran_code' => 'CASH_OUT', 'amount' => '-'.$amount],
                ],
            ]);
            // Reduce pending oldest-first.
            $remaining = $pay;
            $pendingLines = ProfitDistributionLine::where('account_id', $account->id)
                ->where('profit_pending', '>', 0)
                ->whereHas('distribution', fn ($q) => $q->where('status', 'Posted'))
                ->orderBy('distribution_id')->lockForUpdate()->get();
            foreach ($pendingLines as $line) {
                if ($remaining->isZero()) {
                    break;
                }
                $linePending = BigDecimal::of((string) $line->profit_pending);
                $take = $linePending->isLessThan($remaining) ? $linePending : $remaining;
                $line->update(['profit_pending' => (string) $linePending->minus($take)]);
                $remaining = $remaining->minus($take);
            }
            $this->transactions->resyncCache($account);

            return $payment;
        });
    }

    private function profitMappingKey(DepositAccount $account): string
    {
        return match ($account->product->gl_mapping_prefix) {
            'deposit.dps' => 'deposit.profit.distribute.dps',
            'deposit.fdr' => 'deposit.profit.distribute.fdr',
            default => 'deposit.profit.distribute',
        };
    }
}
