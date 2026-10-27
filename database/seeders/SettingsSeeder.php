<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Default settings (plan Step 3 seed list + architecture §15 extras).
 * Values the plan does not fix (deposit rates, fee amounts, closing
 * charge) seed at 0 — the admin sets TPDA's real figures in the UI.
 */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $defs = [
            // key, group, type, value, description
            ['app.name', 'application', 'string', 'TPDA e-Sheba', 'Application name shown everywhere'],
            ['app.locale_default', 'application', 'string', 'en', 'Default language for new users'],
            ['locale.number_system', 'application', 'string', 'arabic', 'Number display: arabic or bengali digits'],
            ['locale.date_format', 'application', 'string', 'd-M-Y', 'Date display format'],
            ['print.header_lines', 'application', 'json', ['Talgachi Poor Development Association (T.P.D.A.)', 'তালগাছী দরিদ্র উন্নয়ন সংস্থা', 'Reg: সিরাজ/৫৮৫/সমাজসেবা/২০০০'], 'Lines printed at the top of reports'],
            ['print.footer_text', 'application', 'string', '', 'Text printed at the bottom of reports'],

            ['loan.default_profit_rate', 'loan', 'number', 15, 'Default loan profit rate % (declining) for new products'],
            ['loan.max_service_charge_rate', 'loan', 'number', 24, 'MRA cap % on the declining-equivalent effective rate'],
            ['loan.allocation_order', 'loan', 'json', ['penalty', 'profit', 'principal'], 'Installment receipt allocation order'],
            ['loan.allow_overpayment', 'loan', 'boolean', true, 'Allow receiving more than the installment due'],
            ['loan.enabled_frequencies', 'loan', 'json', ['D', 'W', 'M'], 'Enabled installment frequencies (D=daily, W=weekly, M=monthly)'],
            ['loan.fee_defaults', 'loan', 'json', ['form' => 0, 'health' => 0, 'risk' => 0, 'document' => 0, 'emi_locker' => 0, 'others' => 0], 'Default loan fee amounts'],
            ['llp.rates', 'loan', 'json', ['standard' => 1, 'watchlist' => 5, 'substandard' => 25, 'doubtful' => 75, 'loss' => 100], 'MRA loan-loss provision % per classification'],
            ['llp.basis', 'loan', 'string', 'net_outstanding', 'LLP basis: outstanding principal minus eligible compulsory savings'],
            ['profit.tax_withholding_rate', 'loan', 'number', 0, 'TDS % withheld on deposit profit (confirm with auditor)'],
            ['accounts.auth_amount_threshold', 'loan', 'number', 50000, 'Vouchers at/above this amount always require authorization'],

            ['deposit.default_profit_rates', 'deposit', 'json', ['savings' => 0, 'dps' => 0, 'fdr' => 0], 'Default annual profit % per deposit type'],
            ['deposit.closing_charge', 'deposit', 'number', 0, 'Charge applied when a deposit account closes'],

            ['sms.sender_id', 'sms', 'string', '', 'SMS sender ID / mask'],
            ['sms.gateway', 'sms', 'string', '', 'SMS gateway provider key'],
            ['sms.credentials', 'sms', 'json', null, 'SMS gateway credentials (set in UI)'],
            ['sms.enabled', 'sms', 'boolean', false, 'Master switch for outbound SMS'],

            ['gps.enabled', 'gps', 'boolean', true, 'GPS tracking of field workers'],
            ['gps.interval_seconds', 'gps', 'number', 60, 'GPS ping interval in seconds'],
            ['gps.retention_days', 'gps', 'number', 90, 'Days GPS history is retained'],
            ['app.max_offline_days', 'gps', 'number', 7, 'Max days the mobile app may work offline'],

            ['business_day.auto_close', 'business_day', 'boolean', false, 'Close every branch business day automatically at 11:59 PM (Asia/Dhaka)'],

            ['collection.sheet_types', 'collection', 'json', ['member', 'samity', 'simple'], 'Collection sheet types in use'],
            ['collection.allow_backdate', 'collection', 'boolean', false, 'Allow back-dated collection entries'],
            ['collection.other_types', 'collection', 'json', ['Admission Fee', 'Passbook Fee', 'Cheque Book Fee', 'Fine', 'Donation', 'Miscellaneous'], 'Other Collection Receive types'],
        ];

        foreach ($defs as [$key, $group, $type, $value, $description]) {
            Setting::updateOrCreate(
                ['key' => $key],
                ['group' => $group, 'type' => $type, 'value' => Setting::encodeValue($value, $type), 'description' => $description]
            );
        }

        // Master TOTP switch: seeded OFF once only. Unlike the rows above
        // (updateOrCreate — a re-seed resets them to their defaults), this
        // one uses firstOrCreate so a re-seed never flips a switch the
        // admin has already set in General Settings.
        Setting::firstOrCreate(
            ['key' => 'security.totp_enabled'],
            ['group' => 'security', 'type' => 'boolean', 'value' => Setting::encodeValue(false, 'boolean'), 'description' => 'Master switch for two-factor authentication (TOTP)']
        );
    }
}
