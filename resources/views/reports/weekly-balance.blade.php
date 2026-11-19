<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { size: A4 portrait; margin: 12mm; }
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
    body { font-family: 'Hind Siliguri', sans-serif; font-size: 11px; color: #000; }
    .sect { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
    .sect td { padding: 0; font-size: 12px; font-weight: 600; }
    .sect td.r { text-align: right; font-weight: 400; }
    table.sheet { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
    table.sheet th, table.sheet td { border: 1px solid #000; padding: 3px 5px; text-align: right; }
    table.sheet th { font-weight: 600; text-align: center; }
    table.sheet td.l { text-align: left; }
    table.sheet td.c { text-align: center; }
    tr.total td { font-weight: 600; }
    .comment { font-size: 11px; margin: 10px 0 34px; }
    .comment .dots { border-bottom: 1px dotted #000; display: inline-block; width: 82%; height: 12px; }
    table.sign { width: 100%; border-collapse: collapse; }
    table.sign td { width: 33%; text-align: center; font-weight: 600; font-size: 11px; padding: 0 12px; }
    table.sign .line { border-bottom: 1px dotted #000; height: 26px; margin-bottom: 4px; }
</style>
</head>
<body>
    @php($bn = fn ($v) => strtr((string) $v, ['0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯']))
    @php($fmt = fn ($v) => $bn(number_format((float) ($v ?? 0), 2)))
    @php($num = fn ($v) => $bn(number_format((int) ($v ?? 0))))

    {{-- Universal PDF header (owner order 2026-11-19): the daily
         register sheet's 3-cell header now tops this sheet too — it
         carries the address line and the title the old ad-hoc lines
         printed, so those are gone; everything from the সঞ্চয় হিসাবঃ
         line down is the client's sheet unchanged. --}}
    @include('reports.partials.header', [
        'title' => 'সাপ্তাহিক স্থিতির হিসাবের সিট',
        'branchName' => $branch->name,
        'leftLines' => ['* শাখার নাম ঃ '.$branch->name],
        'rightLines' => ['* তারিখঃ '.($week ? $bn(\Carbon\Carbon::parse($week['end'])->format('d/m/Y')) : '')],
    ])

    <table class="sect">
        <tr>
            <td>সঞ্চয় হিসাবঃ</td>
            <td class="r">তারিখঃ {{ $week ? $bn(\Carbon\Carbon::parse($week['end'])->format('d/m/Y')) : '' }}</td>
        </tr>
    </table>
    <table class="sheet">
        <thead>
            <tr>
                <th rowspan="2">ক্র. নং</th>
                <th rowspan="2">শাখার নাম</th>
                <th rowspan="2">পূর্বের স্থিতি</th>
                <th rowspan="2">সাপ্তাহিক জের</th>
                <th colspan="2">উত্তোলন</th>
                <th rowspan="2">মোট স্থিতি</th>
            </tr>
            <tr>
                <th>জন</th>
                <th>টাকা</th>
            </tr>
        </thead>
        <tbody>
            @foreach($savings as $i => $row)
                <tr>
                    <td class="c">{{ $bn($i + 1) }}.</td>
                    <td class="l">{{ $row['samity_name'] }}</td>
                    <td>{{ $fmt($row['previous_balance']) }}</td>
                    <td>{{ $fmt($row['weekly_collection']) }}</td>
                    <td>{{ $num($row['withdrawal_count']) }}</td>
                    <td>{{ $fmt($row['withdrawal_amount']) }}</td>
                    <td>{{ $fmt($row['total_balance']) }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td></td>
                <td class="l">মোট =</td>
                <td>{{ $fmt($savingsTotal['previous_balance'] ?? 0) }}</td>
                <td>{{ $fmt($savingsTotal['weekly_collection'] ?? 0) }}</td>
                <td>{{ $num($savingsTotal['withdrawal_count'] ?? 0) }}</td>
                <td>{{ $fmt($savingsTotal['withdrawal_amount'] ?? 0) }}</td>
                <td>{{ $fmt($savingsTotal['total_balance'] ?? 0) }}</td>
            </tr>
        </tbody>
    </table>

    <table class="sect">
        <tr><td>কিস্তি হিসাবঃ</td></tr>
    </table>
    <table class="sheet">
        <thead>
            <tr>
                <th rowspan="2">ক্র. নং</th>
                <th rowspan="2">শাখার নাম</th>
                <th rowspan="2">পূর্বের জের</th>
                <th rowspan="2">সাপ্তাহিক জের</th>
                <th colspan="2">লোন বিতরণ</th>
                <th rowspan="2">মোট স্থিতি</th>
            </tr>
            <tr>
                <th>জন</th>
                <th>টাকা</th>
            </tr>
        </thead>
        <tbody>
            @foreach($loans as $i => $row)
                <tr>
                    <td class="c">{{ $bn($i + 1) }}.</td>
                    <td class="l">{{ $row['samity_name'] }}</td>
                    <td>{{ $fmt($row['previous_dues']) }}</td>
                    <td>{{ $fmt($row['weekly_collection']) }}</td>
                    <td>{{ $num($row['disbursed_count']) }}</td>
                    <td>{{ $fmt($row['disbursed_amount']) }}</td>
                    <td>{{ $fmt($row['total_outstanding']) }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td></td>
                <td class="l">মোট =</td>
                <td>{{ $fmt($loansTotal['previous_dues'] ?? 0) }}</td>
                <td>{{ $fmt($loansTotal['weekly_collection'] ?? 0) }}</td>
                <td>{{ $num($loansTotal['disbursed_count'] ?? 0) }}</td>
                <td>{{ $fmt($loansTotal['disbursed_amount'] ?? 0) }}</td>
                <td>{{ $fmt($loansTotal['total_outstanding'] ?? 0) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="comment">মন্তব্য ঃ <span class="dots"></span></div>

    <table class="sign">
        <tr>
            <td><div class="line"></div>ম্যানেজারের স্বাক্ষর</td>
            <td></td>
            <td><div class="line"></div>পরিচালকের স্বাক্ষর</td>
        </tr>
    </table>
</body>
</html>
