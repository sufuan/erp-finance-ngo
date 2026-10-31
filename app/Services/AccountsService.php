<?php

namespace App\Services;

use App\Models\GroupTransaction;
use App\Models\Ledger;
use App\Models\LedgerGroup;
use App\Models\RecurringSetup;
use App\Models\User;
use App\Models\Voucher;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * AccountsService (Module 9, Steps 26–28) — the manual money workflows
 * behind the Accounts screens. EVERY posting goes through
 * PostingService; this service only shapes lines/movements and guards
 * the manual-entry rules:
 *
 *  - Control ledgers (member savings/loan/share heads…) are posted by
 *    the product engines only — never by a manual voucher. The two
 *    sanctioned exceptions live in contra() (cash ↔ bank) and the
 *    inter-branch flows in TransferService (clearing/inventory).
 *  - Cash-in-hand legs move the ACTOR's cash sub-ledger, exactly like
 *    the deposit/loan engines do (CASH_IN / CASH_OUT movements).
 */
class AccountsService
{
    public const CASH_IN_HAND = '1010';

    public const CASH_AT_BANK = '1020';

    public const INTER_BRANCH_CLEARING = '1130';

    public const INVENTORY = '1200';

    public function __construct(
        private readonly PostingService $posting,
        private readonly BusinessDayService $businessDays,
    ) {}

    public function ledgerByCode(string $code): Ledger
    {
        return Ledger::where('ledger_code', $code)->firstOrFail();
    }

    /** Ledgers a user may pick on manual forms (active, non-control). */
    public function manualLedgers()
    {
        return Ledger::where('is_active', true)->where('is_control', false)
            ->orderBy('ledger_code')->get();
    }

    public function assertManualLedger(Ledger $ledger): void
    {
        if (! $ledger->is_active) {
            throw ValidationException::withMessages(['ledger_id' => __('ledger_inactive')]);
        }
        if ($ledger->is_control) {
            throw ValidationException::withMessages(['ledger_id' => __('control_ledger_not_manual')]);
        }
    }

    /**
     * Account Transaction: one ledger against cash or bank.
     * Receive = money in (Dr cash/bank, Cr ledger, Receipt voucher);
     * Payment = money out (Dr ledger, Cr cash/bank, Payment voucher).
     */
    public function accountTransaction(User $actor, int $branchId, string $side, int $ledgerId, string $source, string $amount, ?string $comments): Voucher
    {
        $ledger = Ledger::findOrFail($ledgerId);
        $this->assertManualLedger($ledger);
        $sourceLedger = $this->ledgerByCode($source === 'bank' ? self::CASH_AT_BANK : self::CASH_IN_HAND);
        $amount = $this->positive($amount);

        $lines = $side === 'Receive'
            ? [['ledger_id' => $sourceLedger->id, 'debit' => $amount, 'credit' => '0.00'],
                ['ledger_id' => $ledger->id, 'debit' => '0.00', 'credit' => $amount]]
            : [['ledger_id' => $ledger->id, 'debit' => $amount, 'credit' => '0.00'],
                ['ledger_id' => $sourceLedger->id, 'debit' => '0.00', 'credit' => $amount]];

        $movements = $source === 'bank' ? [] : [[
            'account_type' => 'cash', 'account_id' => $actor->id,
            'tran_code' => $side === 'Receive' ? 'CASH_IN' : 'CASH_OUT',
            'amount' => $side === 'Receive' ? $amount : '-'.$amount,
        ]];

        return $this->posting->post([
            'branch_id' => $branchId,
            'voucher_type' => $side === 'Receive' ? 'Receipt' : 'Payment',
            'entry_by' => $actor->id,
            'comments' => $comments,
            'lines' => $lines,
            'movements' => $movements,
        ]);
    }

    /** Ledger Transaction: one ledger straight against another. */
    public function ledgerTransaction(User $actor, int $branchId, int $debitLedgerId, int $creditLedgerId, string $amount, ?string $comments): Voucher
    {
        if ($debitLedgerId === $creditLedgerId) {
            throw ValidationException::withMessages(['credit_ledger_id' => __('ledger_same_both_sides')]);
        }
        $debit = Ledger::findOrFail($debitLedgerId);
        $credit = Ledger::findOrFail($creditLedgerId);
        $this->assertManualLedger($debit);
        $this->assertManualLedger($credit);
        $amount = $this->positive($amount);

        return $this->posting->post([
            'branch_id' => $branchId, 'voucher_type' => 'Journal', 'entry_by' => $actor->id,
            'comments' => $comments,
            'lines' => [
                ['ledger_id' => $debit->id, 'debit' => $amount, 'credit' => '0.00'],
                ['ledger_id' => $credit->id, 'debit' => '0.00', 'credit' => $amount],
            ],
        ]);
    }

    /**
     * Journal Entry: free-form multi-line. Each line carries a debit XOR
     * a credit; PostingService enforces the balance + positivity.
     *
     * @param  array<int, array{ledger_id:int, debit:string, credit:string}>  $lines
     */
    public function journalEntry(User $actor, int $branchId, array $lines, ?string $comments): Voucher
    {
        $clean = [];
        foreach ($lines as $line) {
            $ledger = Ledger::findOrFail((int) $line['ledger_id']);
            $this->assertManualLedger($ledger);
            $debit = BigDecimal::of((string) ($line['debit'] ?? '0'));
            $credit = BigDecimal::of((string) ($line['credit'] ?? '0'));
            if ($debit->isNegative() || $credit->isNegative() || ($debit->isZero() && $credit->isZero()) || (! $debit->isZero() && ! $credit->isZero())) {
                throw ValidationException::withMessages(['lines' => __('journal_line_one_side')]);
            }
            $clean[] = ['ledger_id' => $ledger->id, 'debit' => (string) $debit, 'credit' => (string) $credit];
        }
        if (count($clean) < 2) {
            throw ValidationException::withMessages(['lines' => __('journal_needs_two_lines')]);
        }

        return $this->posting->post([
            'branch_id' => $branchId, 'voucher_type' => 'Journal', 'entry_by' => $actor->id,
            'comments' => $comments, 'lines' => $clean,
        ]);
    }

    /** Contra Voucher: cash ↔ bank — the sanctioned control-ledger pair. */
    public function contra(User $actor, int $branchId, string $direction, string $amount, ?string $comments): Voucher
    {
        $cash = $this->ledgerByCode(self::CASH_IN_HAND);
        $bank = $this->ledgerByCode(self::CASH_AT_BANK);
        $amount = $this->positive($amount);
        $toBank = $direction === 'cash_to_bank';

        return $this->posting->post([
            'branch_id' => $branchId, 'voucher_type' => 'Contra', 'entry_by' => $actor->id,
            'comments' => $comments,
            'lines' => $toBank
                ? [['ledger_id' => $bank->id, 'debit' => $amount, 'credit' => '0.00'],
                    ['ledger_id' => $cash->id, 'debit' => '0.00', 'credit' => $amount]]
                : [['ledger_id' => $cash->id, 'debit' => $amount, 'credit' => '0.00'],
                    ['ledger_id' => $bank->id, 'debit' => '0.00', 'credit' => $amount]],
            'movements' => [[
                'account_type' => 'cash', 'account_id' => $actor->id,
                'tran_code' => $toBank ? 'CASH_OUT' : 'CASH_IN',
                'amount' => $toBank ? '-'.$amount : $amount,
            ]],
        ]);
    }

    /**
     * Group Transaction: fill amounts into a ledger group's template
     * lines and post ONE Journal voucher; the register row links the
     * group to the voucher for the Group Transaction List.
     *
     * @param  array<int, string>  $amounts  keyed by ledger_group_lines.id
     */
    public function groupTransaction(User $actor, LedgerGroup $group, array $amounts, ?string $comments): Voucher
    {
        $group->loadMissing('lines.ledger');
        $lines = [];
        foreach ($group->lines as $template) {
            $amount = $amounts[$template->id] ?? null;
            if ($amount === null || $amount === '' || BigDecimal::of((string) $amount)->isZero()) {
                continue;
            }
            $this->assertManualLedger($template->ledger);
            $amount = $this->positive((string) $amount);
            $lines[] = $template->side === 'Debit'
                ? ['ledger_id' => $template->ledger_id, 'debit' => $amount, 'credit' => '0.00']
                : ['ledger_id' => $template->ledger_id, 'debit' => '0.00', 'credit' => $amount];
        }
        if (count($lines) < 2) {
            throw ValidationException::withMessages(['amounts' => __('group_needs_two_amounts')]);
        }

        return DB::transaction(function () use ($actor, $group, $lines, $comments) {
            $voucher = $this->posting->post([
                'branch_id' => $group->branch_id, 'voucher_type' => 'Journal', 'entry_by' => $actor->id,
                'comments' => $comments, 'lines' => $lines,
            ]);
            GroupTransaction::create([
                'branch_id' => $group->branch_id, 'group_id' => $group->id,
                'voucher_id' => $voucher->id, 'entry_by' => $actor->id,
            ]);

            return $voucher;
        });
    }

    /**
     * Post ONE due occurrence of a recurring setup as a Journal voucher.
     * The batch number makes a double-click a no-op at the engine level.
     */
    public function postRecurring(RecurringSetup $setup, User $actor): Voucher
    {
        $debit = Ledger::findOrFail($setup->debit_ledger_id);
        $credit = Ledger::findOrFail($setup->credit_ledger_id);
        $this->assertManualLedger($debit);
        $this->assertManualLedger($credit);

        return DB::transaction(function () use ($setup, $actor, $debit, $credit) {
            $voucher = $this->posting->post([
                'branch_id' => $setup->branch_id, 'voucher_type' => 'Journal', 'entry_by' => $actor->id,
                'batch_number' => 'REC-'.$setup->id.'-'.$setup->next_run_date->toDateString(),
                'comments' => $setup->name,
                'lines' => [
                    ['ledger_id' => $debit->id, 'debit' => (string) $setup->amount, 'credit' => '0.00'],
                    ['ledger_id' => $credit->id, 'debit' => '0.00', 'credit' => (string) $setup->amount],
                ],
            ]);

            $next = CarbonImmutable::parse($setup->next_run_date);
            $next = match ($setup->frequency) {
                'Daily' => $next->addDay(),
                'Weekly' => $next->addWeek(),
                default => $next->addMonth(),
            };
            $setup->update([
                'last_run_date' => $setup->next_run_date,
                'last_voucher_id' => $voucher->id,
                'next_run_date' => $next->toDateString(),
            ]);

            return $voucher;
        });
    }

    private function positive(string $amount): string
    {
        $value = BigDecimal::of($amount);
        if ($value->isZero() || $value->isNegative()) {
            throw new DomainException(__('voucher_amount_must_be_positive'));
        }

        return (string) $value->toScale(2);
    }
}
