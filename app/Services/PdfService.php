<?php

namespace App\Services;

use Mpdf\Mpdf;
use Spatie\LaravelPdf\Facades\Pdf as PdfFacade;

/**
 * PDF rendering.
 *
 * Primary engine: headless Chromium via spatie/laravel-pdf (Browsershot) —
 * DomPDF is NOT used anywhere (it breaks Bengali conjuncts).
 *
 * Fallback for long tabular registers (> 200 rows): mPDF with
 * useOTL => 0xFF, which shapes Bengali conjuncts natively without a
 * browser process (plan Step 0, binding).
 *
 * Heavy renders run through GeneratePdfJob (queued, concurrency-limited)
 * rather than synchronously in a web request.
 */
class PdfService
{
    public const LARGE_REPORT_ROWS = 200;

    /**
     * Render a Blade view to PDF bytes with Chromium.
     */
    public function renderView(string $view, array $data = []): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tpda-pdf-').'.pdf';

        PdfFacade::view($view, $data)
            ->withBrowsershot(function ($browsershot) {
                $browsershot->noSandbox()->setOption('disable-dev-shm-usage', true);
                if ($chrome = env('BROWSERSHOT_CHROME_PATH')) {
                    $browsershot->setChromePath($chrome);
                }
            })
            ->save($path);

        $bytes = file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /**
     * Render raw HTML to PDF bytes with mPDF (large-report fallback).
     *
     * $format/$orientation pick the mPDF page geometry (defaults keep
     * the historical A4 portrait behaviour for every existing caller).
     *
     * The blade's @page block is stripped before WriteHTML: mPDF takes
     * its geometry from this config, NOT from CSS — and a `size:`
     * declaration inside @page sends mPDF into runaway pagination
     * (a bare "Hello" rendered 14 pages; the weekly register rendered
     * 141+ mostly-blank pages on live, 2026-11-19). Chromium, the
     * other engine, never sees this stripped copy — it gets the
     * original HTML with its @page rule intact. Only the two register
     * blades carry an @page rule at all, so no other report changes.
     */
    public function renderLargeHtml(string $html, string $format = 'A4', string $orientation = 'P'): string
    {
        $html = preg_replace('/@page\s*\{[^}]*\}/s', '', $html) ?? $html;
        $fontDir = public_path('fonts');
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => $format.($orientation === 'L' ? '-L' : ''),
            'tempDir' => storage_path('app/mpdf-tmp'),
            'fontDir' => array_merge((new \Mpdf\Config\ConfigVariables)->getDefaults()['fontDir'], [$fontDir]),
            'fontdata' => (new \Mpdf\Config\FontVariables)->getDefaults()['fontdata'] + [
                // Self-hosted Hind Siliguri TTF (mPDF cannot embed woff2).
                //
                // NO 'B' variant on purpose (Bangla "????" bug, 2026-11-18):
                // mPDF loses Bengali OTL shaping for bold runs whenever it
                // has to switch font files mid-run — a registered 'B' file
                // makes every bold Bengali run (<b>, <strong>, h1/h2
                // defaults, font-weight: bold) print as "????" while regular
                // text in the same font renders fine. Pointing 'B' at the
                // Regular file breaks identically, so it is the switch, not
                // the SemiBold file. With no 'B' registered, bold runs stay
                // in the Regular face and conjuncts shape correctly.
                // Second trigger of the same bug: a lang="bn" attribute in
                // the document — autoLangToFont then re-fonts Bengali runs
                // to mPDF's bundled 'freeserif' (LanguageToFont maps bn →
                // freeserif), whose bold face lacks the shaped forms. The
                // PDF blades therefore carry no lang attribute. Keep both
                // conditions out or the headings break again.
                'hindsiliguri' => [
                    'R' => 'HindSiliguri-Regular.ttf',
                    'useOTL' => 0xFF,
                ],
            ],
            'default_font' => 'hindsiliguri',
            // 0xFF = full OpenType layout — required for Bengali conjuncts.
            'useOTL' => 0xFF,
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
        ]);

        $mpdf->WriteHTML($html);

        return $mpdf->Output('', 'S');
    }

    public function shouldUseFallback(int $rowCount): bool
    {
        return $rowCount > self::LARGE_REPORT_ROWS;
    }

    /**
     * Render HTML to PDF: Chromium when the row count is small, mPDF for
     * large reports (plan rule), and mPDF again as a safety net when
     * Chromium is unavailable on the host.
     */
    public function renderHtmlWithFallback(string $html, int $rowCount = 0, string $format = 'A4', string $orientation = 'P'): string
    {
        if ($this->shouldUseFallback($rowCount)) {
            return $this->renderLargeHtml($html, $format, $orientation);
        }
        try {
            $path = tempnam(sys_get_temp_dir(), 'tpda-pdf-').'.pdf';
            PdfFacade::html($html)
                ->withBrowsershot(function ($browsershot) {
                    $browsershot->noSandbox()->setOption('disable-dev-shm-usage', true);
                    if ($chrome = env('BROWSERSHOT_CHROME_PATH')) {
                        $browsershot->setChromePath($chrome);
                    }
                })
                ->save($path);
            $bytes = file_get_contents($path);
            @unlink($path);

            return $bytes;
        } catch (\Throwable) {
            return $this->renderLargeHtml($html, $format, $orientation);
        }
    }
}
