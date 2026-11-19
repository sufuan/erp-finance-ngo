<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { size: A4 landscape; margin: 8mm; }
    @font-face {
        font-family: 'Hind Siliguri';
        src: url('file://{{ public_path('fonts/HindSiliguri-Regular.woff2') }}') format('woff2');
        font-weight: 400;
    }
    @font-face {
        font-family: 'Hind Siliguri';
        src: url('file://{{ public_path('fonts/HindSiliguri-SemiBold.woff2') }}') format('woff2');
        font-weight: 600;
    }
    body { font-family: 'Hind Siliguri', sans-serif; font-size: 8px; color: #000; }
    {{-- The sheet's own header: a 3-cell table — left: logo + সমিতির
         নাম + মাসের নাম; center: the approval line, org names, reg
         line, address and the register title; right: কার্যালয় +
         সপ্তাহ নং. Reproduced from the client's sheet as-is (owner
         order 2026-11-19: exact replication, only data dynamic). --}}
    table.head { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
    table.head td { border: 1px solid #000; padding: 4px 6px; vertical-align: top; }
    table.head td.left { width: 27%; font-size: 10px; line-height: 1.7; }
    table.head td.center { width: 46%; text-align: center; line-height: 1.45; }
    table.head td.right { width: 27%; font-size: 10px; line-height: 1.7; }
    .head .logo { display: block; height: 40px; max-width: 160px; margin-bottom: 2px; }
    .head .govt { font-size: 10px; }
    .head .orgbn { font-size: 15px; font-weight: 600; }
    .head .orgen { font-size: 11px; font-weight: 600; }
    .head .reg { font-size: 10px; }
    .head .addr { font-size: 10px; }
    .head .title { font-size: 13px; font-weight: 600; }
    {{-- Fixed layout + the colgroup + the width styles on the header
         cells pin the 25 columns to the landscape width. mPDF takes
         column widths from the header CELLS (it ignores colgroup
         once the body repeats rows, and dumps the slack into the
         সদস্য ছাড়ার and স্বাক্ষর columns); Chromium honours the
         colgroup. Without them the table also risks mPDF's
         shrink-to-fit collapsing fonts to 0.000pt (live defect,
         2026-11-19). Column SET and ORDER are the sheet's own —
         only widths are tuned. --}}
    table.reg { width: 100%; border-collapse: collapse; table-layout: fixed; }
    table.reg th, table.reg td { border: 1px solid #000; padding: 2px 1px; text-align: right; overflow-wrap: break-word; }
    table.reg th { font-weight: 600; text-align: center; font-size: 8px; }
    table.reg td.l { text-align: left; }
    table.reg td.c { text-align: center; }
    tr.total td { font-weight: 600; }
</style>
</head>
<body>
    @php($bn = fn ($v) => strtr((string) $v, ['0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯']))
    @php($fmt = fn ($v) => $bn(number_format((float) ($v ?? 0), 2)))
    @php($num = fn ($v) => $bn(number_format((int) ($v ?? 0))))
    @php($monthNames = [1 => 'জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর'])
    @php($monthLabel = $monthNames[(int) substr($month, 5, 2)].' '.$bn(substr($month, 0, 4)))
    @php($weekLabel = count($weeks) > 1 ? $bn(1).'–'.$bn(count($weeks)) : $bn(1))

    <table class="head">
        <tr>
            <td class="left">
                @if($org?->logoDataUri())
                    <img class="logo" src="{{ $org->logoDataUri() }}" alt="">
                @endif
                * সমিতির নাম ঃ সকল সমিতি<br>
                * মাসের নাম ঃ {{ $monthLabel }}
            </td>
            <td class="center">
                <div class="govt">গণপ্রজাতন্ত্রী বাংলাদেশ সরকার কর্তৃক অনুমোদিত</div>
                <div class="orgbn">তালগাছী দারিদ্র উন্নয়ন সংস্থা (T.P.D.A)</div>
                <div class="orgen">Talgachi Poor Development Association</div>
                <div class="reg">রেজি: নং- সিরাজঃ ৫৮৫/২০০০</div>
                <div class="addr">তালগাছী, শাহজাদপুর, সিরাজগঞ্জ।</div>
                <div class="title">দৈনিক অফিস তথ্য রেজিস্ট্রার</div>
            </td>
            <td class="right">
                * কার্যালয়ঃ {{ $branch->name }}<br>
                * সপ্তাহ নং- {{ $weekLabel }}
            </td>
        </tr>
    </table>

    @php($rowCells = function ($r) use ($fmt, $num) {
        return [
            $fmt($r['savings_due']), $fmt($r['savings_collected']),
            $num($r['admission_count']), $fmt($r['admission_amount']),
            $num($r['insurance_count']), $fmt($r['insurance_amount']),
            $num($r['withdrawal_count']), $fmt($r['withdrawal_amount']),
            $num($r['member_exit_count']), $fmt($r['other_fees']), $fmt($r['total_in']),
            $num($r['disbursed_count']), $fmt($r['disbursed_amount']),
            $num($r['settled_count']), $fmt($r['settled_amount']),
            $fmt($r['installment_due']), $fmt($r['installment_collected']),
            $fmt($r['total_loan']), $fmt($r['grand_total']),
            $fmt($r['loan_outstanding']), $fmt($r['other_expenses']), $fmt($r['cash_in_hand']),
        ];
    })

    <table class="reg">
        <colgroup>
            <col style="width:2.5%"><col style="width:5.5%"><col style="width:4.5%"><col style="width:4.5%"><col style="width:2.5%">
            <col style="width:4%"><col style="width:2.5%"><col style="width:3.5%"><col style="width:2.5%"><col style="width:4%">
            <col style="width:2.5%"><col style="width:3.5%"><col style="width:5%"><col style="width:2.5%"><col style="width:5%">
            <col style="width:2.5%"><col style="width:4%"><col style="width:4%"><col style="width:4%"><col style="width:5%">
            <col style="width:5.5%"><col style="width:5.5%"><col style="width:4%"><col style="width:5.5%"><col style="width:5.5%">
        </colgroup>
        <thead>
            <tr>
                <th rowspan="2" style="width:2.5%">ক্রমিক নং</th>
                <th rowspan="2" style="width:5.5%">তারিখ</th>
                <th colspan="2" style="width:9%">সঞ্চয়</th>
                <th colspan="2" style="width:6.5%">সদস্য ভর্তি</th>
                <th colspan="2" style="width:6%">ঋণ বীমা</th>
                <th colspan="2" style="width:6.5%">উত্তোলন</th>
                <th style="width:2.5%">সদস্য ছাড়ার</th>
                <th rowspan="2" style="width:3.5%">অন্যান্য</th>
                <th rowspan="2" style="width:5%">মোট</th>
                <th colspan="2" style="width:7.5%">প্রদেয় ঋণ</th>
                <th colspan="2" style="width:6.5%">পরিশোধ</th>
                <th colspan="2" style="width:8%">কিস্তি আদায়</th>
                <th rowspan="2" style="width:5%">মোট</th>
                <th rowspan="2" style="width:5.5%">সর্বমোট</th>
                <th rowspan="2" style="width:5.5%">ঋণের স্থিতি</th>
                <th rowspan="2" style="width:4%">অন্যান্য ব্যয়</th>
                <th rowspan="2" style="width:5.5%">হাতে নগদ</th>
                <th rowspan="2" style="width:5.5%">ম্যানেজারের স্বাক্ষর</th>
            </tr>
            <tr>
                <th style="width:4.5%">যোগ্য</th>
                <th style="width:4.5%">কৃত</th>
                <th style="width:2.5%">জন</th>
                <th style="width:4%">টাকা</th>
                <th style="width:2.5%">জন</th>
                <th style="width:3.5%">টাকা</th>
                <th style="width:2.5%">জন</th>
                <th style="width:4%">টাকা</th>
                <th style="width:2.5%">জন</th>
                <th style="width:2.5%">জন</th>
                <th style="width:5%">টাকা</th>
                <th style="width:2.5%">জন</th>
                <th style="width:4%">টাকা</th>
                <th style="width:4%">যোগ্য</th>
                <th style="width:4%">কৃত</th>
            </tr>
        </thead>
        <tbody>
            @foreach($weeks as $week)
                @foreach($week['rows'] as $ri => $row)
                    <tr>
                        <td class="c">{{ $bn(str_pad((string) ($ri + 1), 2, '0', STR_PAD_LEFT)) }}</td>
                        <td class="l">{{ $bn(\Carbon\Carbon::parse($row['date'])->format('d/m/Y')) }}</td>
                        @foreach($rowCells($row) as $cell)<td>{{ $cell }}</td>@endforeach
                        <td></td>
                    </tr>
                @endforeach
                <tr class="total">
                    <td class="l">মোট</td>
                    <td></td>
                    @foreach($rowCells($week['total']) as $cell)<td>{{ $cell }}</td>@endforeach
                    <td></td>
                </tr>
                <tr class="total">
                    <td class="l">সর্বমোট</td>
                    <td></td>
                    @foreach($rowCells($week['cumulative']) as $cell)<td>{{ $cell }}</td>@endforeach
                    <td></td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
