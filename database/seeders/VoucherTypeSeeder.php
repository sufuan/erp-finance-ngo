<?php

namespace Database\Seeders;

use App\Models\VoucherType;
use Illuminate\Database\Seeder;

/** Voucher types (Step 3A): small receipts auto-authorize at posting. */
class VoucherTypeSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['Receipt', false, 'Receipt Voucher', 'রসিদ ভাউচার'],
            ['Payment', true, 'Payment Voucher', 'পরিশোধ ভাউচার'],
            ['Journal', true, 'Journal Voucher', 'জার্নাল ভাউচার'],
            ['Contra', true, 'Contra Voucher', 'কন্ট্রা ভাউচার'],
        ];
        foreach ($rows as [$code, $auth, $en, $bn]) {
            VoucherType::updateOrCreate(['code' => $code], ['require_authorization' => $auth, 'label_en' => $en, 'label_bn' => $bn]);
        }
    }
}
