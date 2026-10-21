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
     */
    public function renderLargeHtml(string $html): string
    {
        $fontDir = public_path('fonts');
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'tempDir' => storage_path('app/mpdf-tmp'),
            'fontDir' => array_merge((new \Mpdf\Config\ConfigVariables)->getDefaults()['fontDir'], [$fontDir]),
            'fontdata' => (new \Mpdf\Config\FontVariables)->getDefaults()['fontdata'] + [
                // Self-hosted Hind Siliguri TTF (mPDF cannot embed woff2).
                'hindsiliguri' => [
                    'R' => 'HindSiliguri-Regular.ttf',
                    'B' => 'HindSiliguri-SemiBold.ttf',
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
    public function renderHtmlWithFallback(string $html, int $rowCount = 0): string
    {
        if ($this->shouldUseFallback($rowCount)) {
            return $this->renderLargeHtml($html);
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
            return $this->renderLargeHtml($html);
        }
    }
}
