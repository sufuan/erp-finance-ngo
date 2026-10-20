<?php

namespace Database\Seeders;

use App\Models\GlMapping;
use App\Models\Ledger;
use Illuminate\Database\Seeder;

/**
 * Every posting resolves its ledgers through gl_mappings (plan Step 3A) —
 * services never hard-code ledger codes. Keys are added as modules land.
 */
class GlMappingSeeder extends Seeder
{
    public function run(): void
    {
        // key, debit code, credit code, description
        $rows = [
            ['deposit.savings.receive', '1010', '2010', 'Savings deposit received in cash'],
            ['deposit.savings.pay', '2010', '1010', 'Savings withdrawal paid in cash'],
            ['deposit.dps.receive', '1010', '2020', 'DPS installment received in cash'],
            ['deposit.dps.pay', '2020', '1010', 'DPS paid out in cash'],
            ['deposit.fdr.receive', '1010', '2030', 'FDR deposit received in cash'],
            ['deposit.fdr.pay', '2030', '1010', 'FDR paid out in cash'],
            ['deposit.transfer', '2010', '2020', 'Transfer between deposit controls (default pair)'],
            ['deposit.profit.distribute', '5040', '2010', 'Profit distributed to savings accounts'],
            ['deposit.profit.distribute.dps', '5040', '2020', 'Profit distributed to DPS accounts'],
            ['deposit.profit.distribute.fdr', '5040', '2030', 'Profit distributed to FDR accounts'],
            ['deposit.profit.tds', '2010', '2040', 'TDS withheld from member profit'],
            ['deposit.profit.pay', '2010', '1010', 'Profit paid to member in cash'],
            ['deposit.close.charge', '2010', '4035', 'Deposit closing charge to fee income'],

            ['loan.disbursement', '1100', '1010', 'Loan disbursed in cash'],
            ['loan.receive', '1010', '1100', 'Installment received against loan portfolio'],
            ['loan.receive.profit', '1010', '4010', 'Service charge/profit portion of installment'],
            ['loan.fee.form', '1010', '4030', 'Form fee collected'],
            ['loan.fee.health', '1010', '4031', 'Health fee collected'],
            ['loan.fee.risk', '1010', '4032', 'Risk fee collected'],
            ['loan.fee.document', '1010', '4033', 'Document fee collected'],
            ['loan.fee.emi_locker', '1010', '4034', 'EMI locker fee collected'],
            ['loan.fee.others', '1010', '4035', 'Other fees collected'],
            ['loan.penalty', '1010', '4020', 'Penalty collected'],
            ['loan.provision', '5030', '1120', 'Loan loss provision expense'],
            ['loan.writeoff', '1120', '1100', 'Loan written off against provision'],

            ['share.receive', '1010', '3010', 'Share capital received'],
            ['share.pay', '3010', '1010', 'Share capital refunded'],

            ['cash.expense', '5020', '1010', 'Office expense paid in cash'],
            ['salary.pay', '5010', '1010', 'Salary paid in cash'],
            ['cash.handover.out', '1130', '1010', 'Cash handed over (out) via clearing'],
            ['cash.handover.in', '1010', '1130', 'Cash handover received via clearing'],
        ];

        foreach ($rows as [$key, $debit, $credit, $description]) {
            GlMapping::updateOrCreate(
                ['mapping_key' => $key],
                [
                    'debit_ledger_id' => Ledger::byCode($debit)->id,
                    'credit_ledger_id' => Ledger::byCode($credit)->id,
                    'description' => $description,
                ]
            );
        }
    }
}
