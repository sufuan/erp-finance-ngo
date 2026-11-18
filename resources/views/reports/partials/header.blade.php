{{--
    Common header for EVERY PDF (owner order 2026-11-18): the uploaded
    organization logo (Settings → General Settings → Organization) on top,
    then the org name in Bangla + English and the org details line. One
    partial so all reports carry the identical header. The logo is embedded
    as a data URI read from the private disk — the only reference form both
    PDF engines (mPDF + headless Chromium) render; when no logo is uploaded
    the header is name + details only.
--}}
<div class="org">
    @if($org?->logoDataUri())
        <img class="org-logo" src="{{ $org->logoDataUri() }}" alt="">
    @endif
    <div class="bn">{{ $org->name_bn }}</div>
    <div class="en">{{ $org->name_en }}</div>
    <div class="meta">{{ $org->address }} · {{ $org->phone }} · {{ $org->registration_no }}</div>
</div>
