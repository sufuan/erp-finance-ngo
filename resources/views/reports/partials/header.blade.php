{{--
    THE header for EVERY PDF (owner order 2026-11-19): the 3-cell
    header of the client's দৈনিক অফিস তথ্য রেজিস্ট্রার sheet, promoted
    to the one standard header for all reports — the weekly sheet
    included. Left cell: the organization logo uploaded in Settings →
    General Settings → Organization (never a hardcoded image) plus
    document-specific lines; center: the approval line, org names,
    registration line and address (the client's sheet texts, hardcoded
    for this one fixed org) above a dark navy title bar carrying the
    document title; right cell: কার্যালয় (branch) plus document lines.

    Parameters: $org (logo source), $title (title-bar text),
    $branchName (optional কার্যালয় line), $leftLines / $rightLines
    (optional arrays of extra lines for the side cells).

    mPDF notes (the live host renders ONLY via mPDF): the logo <img>
    carries an explicit height attribute — mPDF ignores CSS height /
    max-width on images and would print the logo at natural size (the
    2026-11-19 live defect: a big square logo ate most of the page).
    No @page size rule here — PdfService strips those for mPDF.
--}}
@php($pdfHeadTitle = $title ?? '')
@php($pdfHeadLeft = $leftLines ?? [])
@php($pdfHeadRight = $rightLines ?? [])
<style>
    table.pdf-head { width: 100%; border-collapse: collapse; margin-bottom: 6px; font-family: 'Hind Siliguri', sans-serif; color: #000; }
    table.pdf-head td { border: none; padding: 4px 6px; vertical-align: top; }
    table.pdf-head td.pdf-head-left { width: 27%; font-size: 10px; line-height: 1.7; }
    table.pdf-head td.pdf-head-center { width: 46%; text-align: center; line-height: 1.45; }
    table.pdf-head td.pdf-head-right { width: 27%; font-size: 10px; line-height: 1.7; }
    .pdf-head-logo { display: block; height: 44px; margin-bottom: 2px; }
    .pdf-head-govt { font-size: 10px; }
    .pdf-head-orgbn { font-size: 15px; font-weight: 600; color: #1f4e79; }
    .pdf-head-orgen { font-size: 11px; font-weight: 600; }
    .pdf-head-reg { font-size: 10px; }
    .pdf-head-addr { font-size: 10px; }
    .pdf-head-title { background-color: #1f3864; color: #ffffff; font-size: 13px; font-weight: 600; padding: 3px 4px; margin-top: 3px; }
</style>
<table class="pdf-head">
    <tr>
        <td class="pdf-head-left">
            @if($org?->logoDataUri())
                <img class="pdf-head-logo" height="44" src="{{ $org->logoDataUri() }}" alt="">
            @endif
            @foreach($pdfHeadLeft as $line)
                {{ $line }}@if(! $loop->last)<br>@endif
            @endforeach
        </td>
        <td class="pdf-head-center">
            <div class="pdf-head-govt">গণপ্রজাতন্ত্রী বাংলাদেশ সরকার কর্তৃক অনুমোদিত</div>
            <div class="pdf-head-orgbn">তালগাছী দারিদ্র উন্নয়ন সংস্থা (T.P.D.A)</div>
            <div class="pdf-head-orgen">Talgachi Poor Development Association</div>
            <div class="pdf-head-reg">রেজি: নং- সিরাজঃ ৫৮৫/২০০০</div>
            <div class="pdf-head-addr">তালগাছী, শাহজাদপুর, সিরাজগঞ্জ।</div>
            <div class="pdf-head-title">{{ $pdfHeadTitle }}</div>
        </td>
        <td class="pdf-head-right">
            @if(!empty($branchName))
                * কার্যালয়ঃ {{ $branchName }}@if(count($pdfHeadRight))<br>@endif
            @endif
            @foreach($pdfHeadRight as $line)
                {{ $line }}@if(! $loop->last)<br>@endif
            @endforeach
        </td>
    </tr>
</table>
