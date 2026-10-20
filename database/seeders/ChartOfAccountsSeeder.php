<?php

namespace Database\Seeders;

use App\Models\Ledger;
use Illuminate\Database\Seeder;

/**
 * NGO/MFI chart of accounts (plan Step 3A seed). Control ledgers are
 * auto-posted by the posting engine only — never by manual vouchers.
 */
class ChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        // code, name_en, name_bn, group, is_control
        $rows = [
            ['1010', 'Cash in Hand', 'হাতে নগদ', 'Asset', true],
            ['1020', 'Cash at Bank', 'ব্যাংকে নগদ', 'Asset', true],
            ['1100', 'Member Loans Portfolio', 'সদস্য ঋণ পোর্টফোলিও', 'Asset', true],
            ['1110', 'Profit Receivable', 'প্রাপ্য মুনাফা', 'Asset', false],
            ['1120', 'Loan Loss Provision', 'ঋণ ক্ষতি সঞ্চিতি', 'Asset', false],
            ['1130', 'Inter-Branch Clearing', 'আন্তঃশাখা ক্লিয়ারিং', 'Asset', false],
            ['1150', 'Fixed Assets', 'স্থায়ী সম্পদ', 'Asset', false],
            ['1200', 'Inventory Stock', 'মজুদ পণ্য', 'Asset', true],

            ['2010', 'Member Savings Control', 'সদস্য সঞ্চয় নিয়ন্ত্রণ', 'Liability', true],
            ['2020', 'Member DPS Control', 'সদস্য ডিপিএস নিয়ন্ত্রণ', 'Liability', true],
            ['2030', 'Member FDR Control', 'সদস্য এফডিআর নিয়ন্ত্রণ', 'Liability', true],
            ['2040', 'TDS Payable', 'প্রদেয় টিডিএস', 'Liability', false],
            ['2050', 'Unearned Service Charge', 'অনর্জিত সার্ভিস চার্জ', 'Liability', false],
            ['2060', 'Accounts Payable', 'প্রদেয় হিসাব', 'Liability', false],
            ['2070', 'Salary Payable', 'প্রদেয় বেতন', 'Liability', false],

            ['3010', 'Share Capital', 'শেয়ার মূলধন', 'Equity', true],
            ['3020', 'Retained Earnings (Fund Balance)', 'সংরক্ষিত আয় (তহবিল ব্যালেন্স)', 'Equity', false],
            ['3030', 'Donor Grants Fund', 'দাতা অনুদান তহবিল', 'Equity', false],

            ['4010', 'Service Charge Income', 'সার্ভিস চার্জ আয়', 'Income', false],
            ['4020', 'Penalty Income', 'জরিমানা আয়', 'Income', false],
            ['4030', 'Fee Income — Form', 'ফি আয় — ফর্ম', 'Income', false],
            ['4031', 'Fee Income — Health', 'ফি আয় — স্বাস্থ্য', 'Income', false],
            ['4032', 'Fee Income — Risk', 'ফি আয় — ঝুঁকি', 'Income', false],
            ['4033', 'Fee Income — Document', 'ফি আয় — নথি', 'Income', false],
            ['4034', 'Fee Income — EMI Locker', 'ফি আয় — ইএমআই লকার', 'Income', false],
            ['4035', 'Fee Income — Others', 'ফি আয় — অন্যান্য', 'Income', false],
            ['4040', 'Bank Interest Income', 'ব্যাংক সুদ আয়', 'Income', false],
            ['4050', 'Sales Income', 'বিক্রয় আয়', 'Income', false],

            ['5010', 'Salary Expense', 'বেতন ব্যয়', 'Expense', false],
            ['5020', 'Admin Expenses', 'প্রশাসনিক ব্যয়', 'Expense', false],
            ['5030', 'Provision Expense', 'সঞ্চিতি ব্যয়', 'Expense', false],
            ['5040', 'Deposit Profit Expense', 'আমানত মুনাফা ব্যয়', 'Expense', false],
            ['5050', 'Rent Expense', 'ভাড়া ব্যয়', 'Expense', false],
            ['5060', 'Utilities Expense', 'ইউটিলিটি ব্যয়', 'Expense', false],
            ['5070', 'Cost of Goods Sold', 'বিক্রিত পণ্যের ব্যয়', 'Expense', false],
        ];

        foreach ($rows as [$code, $en, $bn, $group, $control]) {
            Ledger::updateOrCreate(
                ['ledger_code' => $code],
                ['name_en' => $en, 'name_bn' => $bn, 'ledger_group' => $group, 'is_control' => $control, 'is_active' => true]
            );
        }
    }
}
