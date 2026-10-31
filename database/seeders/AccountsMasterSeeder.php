<?php

namespace Database\Seeders;

use App\Models\BillingType;
use Illuminate\Database\Seeder;

/** Accounts masters' starter rows (Steps 26–30): editable in the UI. */
class AccountsMasterSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['Office Rent', 'অফিস ভাড়া'],
            ['Utility Bill', 'ইউটিলিটি বিল'],
            ['Stationery', 'স্টেশনারি'],
            ['Transport', 'যাতায়াত'],
            ['Miscellaneous', 'বিবিধ'],
        ];
        foreach ($rows as [$en, $bn]) {
            BillingType::updateOrCreate(['name_en' => $en], ['name_bn' => $bn, 'is_active' => true]);
        }
    }
}
