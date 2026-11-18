<?php

use App\Models\DepositProduct;
use App\Models\GlMapping;
use App\Models\Ledger;
use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Client requirements round (confirmed 2026-11-18) — data migration.
 *
 * Deploys run `migrate --force` but NOT the seeders, so every new
 * live value arrives here, idempotently (firstOrCreate /
 * updateOrCreate — safe to re-run). The same entries live in the
 * seeders for fresh installs.
 *
 *  - loan_fees.label — custom "other fee" rows carry their own name.
 *  - loan_accounts.savings_deposit_amount / savings_account_id — the
 *    loan-linked savings split recorded at disbursement.
 *  - Ledger 4036 + GlMapping loan.fee.insurance — the insurance fee
 *    (1% of the loan) posts to its OWN income ledger.
 *  - Settings: loan.linked_savings_product (SAVINGS),
 *    loan.savings_deposit_rate (10%), loan.insurance_rate (1%), and
 *    an 'insurance' key inside loan.fee_defaults.
 *  - LTS product config: the client's printed payout table
 *    (authoritative transcription, 2026-11-18) + premature slabs.
 */
return new class extends Migration
{
    public function up(): void
    {
        // fee_type is an ENUM from the original loan tables migration —
        // widen it with the two new fee kinds (insurance + named custom
        // rows). MODIFY with the full list is idempotent in effect.
        DB::statement("ALTER TABLE loan_fees MODIFY fee_type ENUM('form','health','risk','document','emi_locker','others','insurance','custom') NOT NULL");
        if (! Schema::hasColumn('loan_fees', 'label')) {
            Schema::table('loan_fees', function (Blueprint $table) {
                $table->string('label', 100)->nullable()->after('fee_type');
            });
        }
        if (! Schema::hasColumn('loan_accounts', 'savings_deposit_amount')) {
            Schema::table('loan_accounts', function (Blueprint $table) {
                $table->decimal('savings_deposit_amount', 15, 2)->default(0)->after('loan_amount');
                $table->unsignedBigInteger('savings_account_id')->nullable()->after('savings_deposit_amount');
            });
        }

        $insuranceLedger = Ledger::updateOrCreate(
            ['ledger_code' => '4036'],
            ['name_en' => 'Fee Income — Insurance', 'name_bn' => 'ফি আয় — বীমা', 'ledger_group' => 'Income', 'is_control' => false, 'is_active' => true]
        );
        $cash = Ledger::byCode('1010');
        if ($cash) {
            GlMapping::updateOrCreate(
                ['mapping_key' => 'loan.fee.insurance'],
                ['debit_ledger_id' => $cash->id, 'credit_ledger_id' => $insuranceLedger->id, 'description' => 'Loan insurance fee received']
            );
        }

        $settings = [
            ['loan.linked_savings_product', 'loan', 'string', 'SAVINGS', 'Deposit product code auto-opened with a loan and collected with every installment'],
            ['loan.savings_deposit_rate', 'loan', 'number', 10, 'Savings deposit % of the loan amount, credited at disbursement'],
            ['loan.insurance_rate', 'loan', 'number', 1, 'Insurance fee % of the loan amount (charged at opening)'],
        ];
        foreach ($settings as [$key, $group, $type, $value, $description]) {
            Setting::firstOrCreate(
                ['key' => $key],
                ['value' => Setting::encodeValue($value, $type), 'type' => $type, 'group' => $group, 'description' => $description]
            );
        }

        $feeDefaults = Setting::where('key', 'loan.fee_defaults')->first();
        if ($feeDefaults) {
            $decoded = $feeDefaults->decodedValue();
            if (is_array($decoded) && ! array_key_exists('insurance', $decoded)) {
                $decoded['insurance'] = 0;
                $feeDefaults->update(['value' => Setting::encodeValue($decoded, 'json')]);
            }
        }

        $lts = DepositProduct::where('code', 'LTS')->first();
        if ($lts) {
            $config = is_array($lts->config) ? $lts->config : [];
            $config['payout_table'] = self::payoutTable();
            $config['premature_slabs'] = [
                ['min_years' => 0, 'max_years' => 1, 'rate' => 0],
                ['min_years' => 1, 'max_years' => 2, 'rate' => 7],
                ['min_years' => 2, 'max_years' => 3, 'rate' => 9],
                ['min_years' => 3, 'max_years' => 4, 'rate' => 11],
                ['min_years' => 4, 'max_years' => 5, 'rate' => 13],
                ['min_years' => 5, 'max_years' => null, 'rate' => 13],
            ];
            $lts->update(['config' => $config]);
        }
    }

    public function down(): void
    {
        // Data additions (ledger, mapping, settings) are left in place —
        // removing live chart-of-accounts rows would break posted
        // vouchers. Only the additive columns are dropped.
        if (Schema::hasColumn('loan_accounts', 'savings_deposit_amount')) {
            Schema::table('loan_accounts', function (Blueprint $table) {
                $table->dropColumn(['savings_deposit_amount', 'savings_account_id']);
            });
        }
        if (Schema::hasColumn('loan_fees', 'label')) {
            Schema::table('loan_fees', function (Blueprint $table) {
                $table->dropColumn('label');
            });
        }
    }

    /**
     * The client's printed LTS maturity-profit table (taka) by daily
     * deposit amount × term in years — authoritative transcription
     * from the terms leaflet (photo re-sent 2026-11-18).
     */
    public static function payoutTable(): array
    {
        return [
            '50' => ['1' => 456, '2' => 1648, '3' => 3889, '4' => 7521, '5' => 12963],
            '100' => ['1' => 913, '2' => 3298, '3' => 7774, '4' => 15045, '5' => 25930],
            '200' => ['1' => 1827, '2' => 6598, '3' => 15551, '4' => 30094, '5' => 51866],
            '300' => ['1' => 2740, '2' => 9896, '3' => 23326, '4' => 45141, '5' => 77999],
            '400' => ['1' => 3654, '2' => 13196, '3' => 31103, '4' => 60190, '5' => 103708],
            '500' => ['1' => 4567, '2' => 16495, '3' => 38879, '4' => 75238, '5' => 129668],
            '600' => ['1' => 5481, '2' => 19794, '3' => 46655, '4' => 90286, '5' => 155602],
            '700' => ['1' => 6394, '2' => 23093, '3' => 54431, '4' => 105334, '5' => 181536],
            '800' => ['1' => 7308, '2' => 26392, '3' => 62207, '4' => 120382, '5' => 207490],
            '900' => ['1' => 8221, '2' => 29691, '3' => 69983, '4' => 135430, '5' => 233404],
            '1000' => ['1' => 9135, '2' => 32991, '3' => 77760, '4' => 150479, '5' => 259309],
        ];
    }
};
