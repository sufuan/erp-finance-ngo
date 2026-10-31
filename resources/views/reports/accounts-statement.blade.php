<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="utf-8">
<style>
    @font-face {
        font-family: 'Hind Siliguri';
        src: url('{{ public_path('fonts/HindSiliguri-Regular.woff2') }}') format('woff2');
        font-weight: 400;
    }
    @font-face {
        font-family: 'Hind Siliguri';
        src: url('{{ public_path('fonts/HindSiliguri-SemiBold.woff2') }}') format('woff2');
        font-weight: 600;
    }
    body { font-family: 'Hind Siliguri', sans-serif; font-size: 12px; color: #18181b; }
    .org { text-align: center; margin-bottom: 4px; }
    .org .bn { font-size: 20px; font-weight: 600; }
    .org .en { font-size: 13px; color: #3f3f46; }
    .org .meta { font-size: 11px; color: #52525b; }
    h1 { font-size: 16px; text-align: center; margin: 10px 0 4px; }
    h2 { font-size: 13px; margin: 14px 0 4px; border-bottom: 1px solid #d4d4d8; padding-bottom: 2px; }
    .filters { text-align: center; font-size: 11px; color: #52525b; margin-bottom: 10px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #d4d4d8; padding: 4px 6px; text-align: left; }
    th { background: #f4f4f5; font-weight: 600; }
    td.amt, th.amt { text-align: right; }
    tr.total td { font-weight: 600; background: #fafafa; }
    .summary { margin-top: 14px; }
    .footer { margin-top: 12px; font-size: 10px; color: #71717a; text-align: right; }
</style>
</head>
<body>
    <div class="org">
        <div class="bn">{{ $org->name_bn }}</div>
        <div class="en">{{ $org->name_en }}</div>
        <div class="meta">{{ $org->address }} · {{ $org->phone }} · {{ $org->registration_no }}</div>
    </div>
    <h1>{{ $title }}</h1>
    @if(!empty($filter_summary))
        <div class="filters">{{ $filter_summary }}</div>
    @endif

    @foreach($sections as $section)
        @if(!empty($section['heading']))<h2>{{ $section['heading'] }}</h2>@endif
        @if(!empty($section['table']))
            <table>
                <thead><tr>@foreach($section['table']['columns'] as $column)<th>{{ $column }}</th>@endforeach</tr></thead>
                <tbody>
                    @foreach($section['table']['rows'] as $row)
                        <tr>@foreach($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <table>
                <tbody>
                    @foreach($section['rows'] as $row)
                        <tr><td>{{ $row[0] }}</td><td class="amt">{{ $row[1] }}</td></tr>
                    @endforeach
                    @if(!empty($section['total']))
                        <tr class="total"><td>{{ $section['total'][0] }}</td><td class="amt">{{ $section['total'][1] }}</td></tr>
                    @endif
                </tbody>
            </table>
        @endif
    @endforeach

    @if(!empty($summary))
        <table class="summary">
            <tbody>
                @foreach($summary as $pair)
                    <tr><td>{{ $pair[0] }}</td><td class="amt">{{ is_bool($pair[1]) ? ($pair[1] ? '✓' : '✗') : $pair[1] }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif
    <div class="footer">{{ now()->format('Y-m-d H:i') }}</div>
</body>
</html>
