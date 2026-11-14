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
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #d4d4d8; padding: 4px 6px; text-align: left; }
    th { background: #f4f4f5; font-weight: 600; }
    td.lbl { width: 32%; background: #fafafa; font-weight: 600; }
    td.amt, th.amt { text-align: right; }
    .signatures { margin-top: 56px; width: 100%; }
    .signatures td { border: none; text-align: center; width: 33%; }
    .signatures .line { border-top: 1px solid #18181b; margin: 0 12px; padding-top: 4px; }
    .footer { margin-top: 12px; font-size: 10px; color: #71717a; text-align: right; }
</style>
</head>
<body>
    <div class="org">
        <div class="bn">{{ $org->name_bn }}</div>
        <div class="en">{{ $org->name_en }}</div>
        <div class="meta">{{ $org->address }} · {{ $org->phone }} · {{ $org->registration_no }}</div>
    </div>
    <h1>Loan Proposal Report — ঋণ প্রস্তাব রিপোর্ট</h1>

    <h2>Proposal — প্রস্তাব</h2>
    <table>
        <tbody>
            <tr><td class="lbl">Proposal No</td><td>{{ $proposal->id }}</td></tr>
            <tr><td class="lbl">Proposal Date</td><td>{{ $proposal->proposal_date?->toDateString() }}</td></tr>
            <tr><td class="lbl">Status</td><td>{{ $proposal->status }}</td></tr>
            <tr><td class="lbl">Branch</td><td>{{ $proposal->branch ? $proposal->branch->code.' — '.$proposal->branch->name : '—' }}</td></tr>
            <tr><td class="lbl">Samity</td><td>{{ $proposal->samity ? $proposal->samity->samity_code.' — '.$proposal->samity->name : '—' }}</td></tr>
        </tbody>
    </table>

    <h2>Member — সদস্য</h2>
    <table>
        <tbody>
            <tr><td class="lbl">Member Name</td><td>{{ $proposal->member?->name }}</td></tr>
            <tr><td class="lbl">Member No</td><td>{{ $proposal->member?->member_no }}</td></tr>
            <tr><td class="lbl">Mobile</td><td>{{ $proposal->member?->mobile ?? '—' }}</td></tr>
            <tr><td class="lbl">Address</td><td>{{ $proposal->member?->present_address ?: ($proposal->member?->permanent_address ?? '—') }}</td></tr>
        </tbody>
    </table>

    <h2>Loan Terms — ঋণের শর্তাবলি</h2>
    <table>
        <tbody>
            <tr><td class="lbl">Loan Product</td><td>{{ $proposal->product ? $proposal->product->code.' — '.$proposal->product->name_en : '—' }}</td></tr>
            <tr><td class="lbl">Loan Purpose</td><td>{{ $proposal->loan_purpose ?? '—' }}</td></tr>
            <tr><td class="lbl">Loan Amount</td><td class="amt">{{ number_format((float) $proposal->loan_amount, 2) }}</td></tr>
            <tr><td class="lbl">Profit Rate (%)</td><td class="amt">{{ $proposal->profit_rate }}</td></tr>
            <tr><td class="lbl">Profit Amount</td><td class="amt">{{ number_format((float) $proposal->profit_amount, 2) }}</td></tr>
            <tr><td class="lbl">Total Repayment</td><td class="amt">{{ number_format((float) $proposal->total_repayment, 2) }}</td></tr>
            <tr><td class="lbl">Frequency</td><td>{{ $frequencyLabel }}</td></tr>
            <tr><td class="lbl">No. of Installments</td><td class="amt">{{ $proposal->no_of_installments }}</td></tr>
            <tr><td class="lbl">Installment Amount</td><td class="amt">{{ number_format((float) $proposal->installment_amount, 2) }}</td></tr>
            <tr><td class="lbl">Installment Start Date</td><td>{{ $proposal->installment_from_date?->toDateString() }}</td></tr>
            <tr><td class="lbl">Installment Expire Date</td><td>{{ $proposal->installment_expire_date?->toDateString() }}</td></tr>
        </tbody>
    </table>

    @if(!empty($proposal->guarantors))
        <h2>Guarantors — জামিনদার</h2>
        <table>
            <thead><tr><th>Name</th><th>Relation</th><th>Phone</th><th>Address</th><th>National ID</th></tr></thead>
            <tbody>
                @foreach($proposal->guarantors as $guarantor)
                    <tr>
                        <td>{{ $guarantor['name'] ?? '—' }}</td>
                        <td>{{ $guarantor['relation'] ?? '—' }}</td>
                        <td>{{ $guarantor['phone'] ?? '—' }}</td>
                        <td>{{ $guarantor['address'] ?? '—' }}</td>
                        <td>{{ $guarantor['national_id'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>Decision — সিদ্ধান্ত</h2>
    <table>
        <tbody>
            <tr><td class="lbl">Proposed By</td><td>{{ $proposedBy ?? '—' }}</td></tr>
            <tr><td class="lbl">{{ $proposal->status === 'Rejected' ? 'Rejected By' : 'Approved By' }}</td><td>{{ $decidedBy ?? '—' }}</td></tr>
            <tr><td class="lbl">Decision Date</td><td>{{ $proposal->decided_at?->format('Y-m-d H:i') ?? '—' }}</td></tr>
        </tbody>
    </table>

    @if($loan)
        <h2>Disbursement — বিতরণ</h2>
        <table>
            <tbody>
                <tr><td class="lbl">Loan No</td><td>{{ $loan->loan_number }}</td></tr>
                <tr><td class="lbl">Opening Date</td><td>{{ $loan->opening_date?->toDateString() }}</td></tr>
                <tr><td class="lbl">Loan Status</td><td>{{ $loan->status }}</td></tr>
                <tr><td class="lbl">Disbursement Date</td><td>{{ $loan->disbursed_at?->format('Y-m-d') ?? '—' }}</td></tr>
                <tr><td class="lbl">Disbursed Amount</td><td class="amt">{{ number_format((float) $loan->loan_amount, 2) }}</td></tr>
            </tbody>
        </table>
    @endif

    <table class="signatures">
        <tbody>
            <tr>
                <td><div class="line">Prepared By</div></td>
                <td><div class="line">Approved By</div></td>
                <td><div class="line">Member's Signature</div></td>
            </tr>
        </tbody>
    </table>
    <div class="footer">{{ now()->format('Y-m-d H:i') }}</div>
</body>
</html>
