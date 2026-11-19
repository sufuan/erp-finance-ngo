<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
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
    body { font-family: 'Hind Siliguri', sans-serif; font-size: 12px; color: #18181b; }
    h2 { font-size: 13px; margin: 14px 0 4px; border-bottom: 1px solid #d4d4d8; padding-bottom: 2px; }
    .filters { text-align: center; font-size: 11px; color: #52525b; margin-bottom: 10px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #d4d4d8; padding: 4px 6px; text-align: left; }
    th { background: #f4f4f5; font-weight: 600; }
    td.lbl { width: 55%; background: #fafafa; font-weight: 600; }
    td.amt, th.amt { text-align: right; }
    .footer { margin-top: 12px; font-size: 10px; color: #71717a; text-align: right; }
</style>
</head>
<body>
    @include('reports.partials.header', [
        'title' => __('director_dashboard'),
        'branchName' => 'সকল শাখা',
        'leftLines' => ['* তারিখঃ '.$asOn],
    ])
    <div class="filters">{{ __('dash.as_on') }}: {{ $asOn }} · {{ __('dash.all_branches') }}</div>

    @php($fmt = fn ($v) => number_format((float) ($v ?? 0), 2))
    @php($num = fn ($v) => number_format((int) ($v ?? 0)))

    <h2>{{ __('director.today') }}</h2>
    <table>
        <tbody>
            <tr><td class="lbl">{{ __('director.deposit_collection') }}</td><td class="amt">{{ $fmt($today['deposit_collection']) }}</td></tr>
            <tr><td class="lbl">{{ __('director.installment_due') }}</td><td class="amt">{{ $fmt($today['due_today']) }}</td></tr>
            <tr><td class="lbl">{{ __('director.loan_collection') }}</td><td class="amt">{{ $fmt($today['loan_collection']) }}</td></tr>
            <tr><td class="lbl">{{ __('director.disbursed') }}</td><td class="amt">{{ $fmt($today['disbursed']) }} ({{ $num($today['disbursed_count']) }})</td></tr>
            <tr><td class="lbl">{{ __('director.new_members') }}</td><td class="amt">{{ $num($today['new_members']) }}</td></tr>
            <tr><td class="lbl">{{ __('acc_income') }}</td><td class="amt">{{ $fmt($today['income']) }}</td></tr>
            <tr><td class="lbl">{{ __('acc_expenses') }}</td><td class="amt">{{ $fmt($today['expense']) }}</td></tr>
        </tbody>
    </table>

    <h2>{{ __('director.this_month') }}</h2>
    <table>
        <tbody>
            <tr><td class="lbl">{{ __('director.deposit_collection') }}</td><td class="amt">{{ $fmt($month['deposit_collection']) }}</td></tr>
            <tr><td class="lbl">{{ __('director.loan_collection') }}</td><td class="amt">{{ $fmt($month['loan_collection']) }}</td></tr>
            <tr><td class="lbl">{{ __('director.disbursed') }}</td><td class="amt">{{ $fmt($month['disbursed']) }} ({{ $num($month['disbursed_count']) }})</td></tr>
            <tr><td class="lbl">{{ __('director.new_members') }}</td><td class="amt">{{ $num($month['new_members']) }}</td></tr>
            <tr><td class="lbl">{{ __('acc_income') }}</td><td class="amt">{{ $fmt($month['income']) }}</td></tr>
            <tr><td class="lbl">{{ __('acc_expenses') }}</td><td class="amt">{{ $fmt($month['expense']) }}</td></tr>
        </tbody>
    </table>

    <h2>{{ __('director.totals') }}</h2>
    <table>
        <tbody>
            <tr><td class="lbl">{{ __('total_members') }}</td><td class="amt">{{ $num($totals['members']) }}</td></tr>
            <tr><td class="lbl">{{ __('director.active_loans') }}</td><td class="amt">{{ $num($totals['active_loans']) }}</td></tr>
            <tr><td class="lbl">{{ __('director.outstanding') }}</td><td class="amt">{{ $fmt($totals['outstanding']) }}</td></tr>
            <tr><td class="lbl">{{ __('director.deposit_balance') }}</td><td class="amt">{{ $fmt($totals['deposit_balance']) }}</td></tr>
        </tbody>
    </table>

    <h2>{{ __('branch') }}</h2>
    <table>
        <thead>
            <tr>
                <th>{{ __('branch') }}</th>
                <th>{{ __('business_date') }}</th>
                <th class="amt">{{ __('director.deposit_collection') }}</th>
                <th class="amt">{{ __('director.installment_due') }}</th>
                <th class="amt">{{ __('director.loan_collection') }}</th>
                <th class="amt">{{ __('director.disbursed') }}</th>
                <th class="amt">{{ __('director.new_members') }}</th>
                <th class="amt">{{ __('total_members') }}</th>
                <th class="amt">{{ __('director.outstanding') }}</th>
                <th class="amt">{{ __('director.deposit_balance') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($branches as $b)
                <tr>
                    <td>{{ $b['code'] }} — {{ $b['name'] }}</td>
                    <td>{{ $b['business_date'] }}</td>
                    <td class="amt">{{ $fmt($b['today']['deposit_collection']) }}</td>
                    <td class="amt">{{ $fmt($b['today']['due_today']) }}</td>
                    <td class="amt">{{ $fmt($b['today']['loan_collection']) }}</td>
                    <td class="amt">{{ $fmt($b['today']['disbursed']) }}</td>
                    <td class="amt">{{ $num($b['today']['new_members']) }}</td>
                    <td class="amt">{{ $num($b['totals']['members']) }}</td>
                    <td class="amt">{{ $fmt($b['totals']['outstanding']) }}</td>
                    <td class="amt">{{ $fmt($b['totals']['deposit_balance']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <div class="footer">{{ now()->format('Y-m-d H:i') }}</div>
</body>
</html>
