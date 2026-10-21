<?php

namespace App\Services;

use App\Models\AccountTransaction;
use App\Models\DepositAccount;
use App\Models\DepositClosing;
use App\Models\GlMapping;
use App\Models\User;
use App\Models\Voucher;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Step 12 — deposit account closing. Payable = balance + profit_payment
 * − closing_charge (setting `deposit.closing_charge`) − premature penalty
 * (the Step 10 preview, charged when closing before maturity). One
 * Payment voucher carries the whole composition — debit the product
 * control for the balance, debit profit expense for the profit, credit
 * cash for the payable, credit fee/penalty income for the deductions —
 * and the deposit movement zeroes the account. All ledgers resolve
 * through gl_mappings; nothing is hard-coded.
 */
class DepositClosingService
{
    public function __construct(
        private PostingService $posting,
        private BusinessDayService $businessDays,
        private SettingsService $settings,
        private DepositAccountService $accounts,
        private DepositTransactionService $transactions,
    ) {}

    /** @return array{balance: string, profit_payment: string, closing_charge: string, penalty: string, payable: string, is_premature: bool} */
    public function preview(DepositAccount $account, ?string $closingDate = null, string $profitPayment = '0'): array
    {
        $account->loadMissing('product');
        $charge = BigDecimal::of((string) ($this->settings->get('deposit.closing_charge', 0) ?? 0));
        $premature = $this->accounts->prematureClosePreview($account, $closingDate);
        $balance = BigDecimal::of($premature['balance']);
        $penalty = BigDecimal::of($premature['penalty']);
        $profit = BigDecimal::of($profitPayment);
        $payable = $balance->plus($profit)->minus($charge)->minus($penalty);
        if ($payable->isNegative()) {
            $payable = BigDecimal::zero();
        }

        return [
            'balance' => (string) $balance->toScale(2, RoundingMode::HalfUp),
            'profit_payment' => (string) $profit->toScale(2, RoundingMode::HalfUp),
            'closing_charge' => (string) $charge->toScale(2, RoundingMode::HalfUp),
            'penalty' => (string) $penalty->toScale(2, RoundingMode::HalfUp),
            'payable' => (string) $payable->toScale(2, RoundingMode::HalfUp),
            'is_premature' => $premature['is_premature'],
        ];
    }

    public function close(DepositAccount $account, string $closingDate, ?string $reason, User $actor, string $profitPayment = '0'): DepositClosing
    {
        return DB::transaction(function () use ($account, $closingDate, $reason, $actor, $profitPayment) {
            $account = DepositAccount::with('product')->lockForUpdate()->findOrFail($account->id);
            if (! $account->is_active || $account->closing_date !== null) {
                throw ValidationException::withMessages(['account_id' => __('account_closed_or_inactive')]);
            }
            $this->accounts->assertClosable($account); // active lien blocks

            $figures = $this->preview($account, $closingDate, $profitPayment);
            $closing = DepositClosing::create([
                'account_id' => $account->id, 'closing_date' => $closingDate,
                'balance' => $figures['balance'], 'profit_payment' => $figures['profit_payment'],
                'closing_charge' => $figures['closing_charge'], 'payable_amount' => $figures['payable'],
                'reason' => $reason, 'closed_by' => $actor->id, 'closed_at' => now(),
            ]);

            $balance = BigDecimal::of($figures['balance']);
            $profit = BigDecimal::of($figures['profit_payment']);
            if (! $balance->isZero() || ! $profit->isZero()) {
                $payMapping = GlMapping::byKey($account->product->gl_mapping_prefix.'.pay');
                $chargeMapping = GlMapping::byKey('deposit.close.charge');
                $penaltyMapping = GlMapping::byKey('deposit.close.penalty');
                $profitMapping = GlMapping::byKey($this->profitMappingKey($account));
                $lines = [];
                if (! $balance->isZero()) {
                    $lines[] = ['ledger_id' => $payMapping->debit_ledger_id, 'debit' => $figures['balance'], 'credit' => '0.00', 'deposit_account_id' => $account->id];
                }
                if (! $profit->isZero() && $profitMapping) {
                    $lines[] = ['ledger_id' => $profitMapping->debit_ledger_id, 'debit' => $figures['profit_payment'], 'credit' => '0.00', 'deposit_account_id' => $account->id];
                }
                $lines[] = ['ledger_id' => $payMapping->credit_ledger_id, 'debit' => '0.00', 'credit' => $figures['payable'], 'deposit_account_id' => $account->id];
                if (! BigDecimal::of($figures['closing_charge'])->isZero() && $chargeMapping) {
                    $lines[] = ['ledger_id' => $chargeMapping->credit_ledger_id, 'debit' => '0.00', 'credit' => $figures['closing_charge'], 'deposit_account_id' => $account->id];
                }
                if (! BigDecimal::of($figures['penalty'])->isZero() && $penaltyMapping) {
                    $lines[] = ['ledger_id' => $penaltyMapping->credit_ledger_id, 'debit' => '0.00', 'credit' => $figures['penalty'], 'deposit_account_id' => $account->id];
                }

                $this->posting->post([
                    'branch_id' => $account->branch_id, 'voucher_type' => 'Payment', 'entry_by' => $actor->id,
                    'transaction_date' => $this->businessDays->currentDate($account->branch_id),
                    'comments' => $reason,
                    'lines' => $lines,
                    'movements' => [
                        ['account_type' => 'deposit', 'account_id' => $account->id, 'tran_code' => 'CLOSING', 'amount' => '-'.$figures['balance'], 'ref_type' => 'deposit_closing', 'ref_id' => $closing->id],
                        ['account_type' => 'cash', 'account_id' => $actor->id, 'tran_code' => 'CASH_OUT', 'amount' => '-'.$figures['payable']],
                    ],
                ]);
            }

            $account->update(['closing_date' => $closingDate, 'is_active' => false, 'updated_by' => $actor->id]);
            $this->transactions->resyncCache($account);

            return $closing->fresh();
        });
    }

    public function cancelClosing(DepositClosing $closing, User $by): void
    {
        DB::transaction(function () use ($closing, $by) {
            if ($closing->cancelled_at !== null) {
                throw ValidationException::withMessages(['closing' => __('closing_already_cancelled')]);
            }
            $account = DepositAccount::lockForUpdate()->findOrFail($closing->account_id);
            $voucherId = AccountTransaction::where('ref_type', 'deposit_closing')
                ->where('ref_id', $closing->id)->value('voucher_id');
            if ($voucherId) {
                $this->posting->cancel(Voucher::findOrFail($voucherId), $by, 'Deposit closing cancelled');
            }
            $closing->update(['cancelled_by' => $by->id, 'cancelled_at' => now()]);
            $account->update(['closing_date' => null, 'is_active' => true, 'updated_by' => $by->id]);
            $this->transactions->resyncCache($account);
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
