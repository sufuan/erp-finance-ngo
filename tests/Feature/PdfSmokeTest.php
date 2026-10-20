<?php

namespace Tests\Feature;

use App\Services\PdfService;
use Tests\TestCase;

/**
 * Plan Step 0 / G10: Bengali conjuncts must render in PDFs — no tofu boxes.
 * Chromium (Browsershot) is the primary engine; mPDF+OTL is the fallback
 * for >200-row registers.
 */
class PdfSmokeTest extends TestCase
{
    private function chromePath(): ?string
    {
        if ($env = env('BROWSERSHOT_CHROME_PATH')) {
            return file_exists($env) ? $env : null;
        }
        $candidates = glob((getenv('HOME') ?: '/root').'/.cache/puppeteer/chrome/*/chrome-linux64/chrome') ?: [];

        return $candidates[0] ?? null;
    }

    public function test_mpdf_fallback_renders_bengali_conjuncts(): void
    {
        $bytes = app(PdfService::class)->renderLargeHtml(
            '<h1>TPDA e-Sheba</h1><p style="font-family: hindsiliguri;">ক্ষ জ্ঞ ১২৩ — পাঁচ হাজার টাকা মাত্র</p>'
        );

        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertGreaterThan(3000, strlen($bytes));
    }

    public function test_chromium_renders_bengali_pdf_smoke_view(): void
    {
        $chrome = $this->chromePath();
        if (! $chrome) {
            $this->markTestSkipped('Chromium not installed locally (npx puppeteer browsers install chrome). CI installs it; see BUILD_LOG.md.');
        }
        putenv('BROWSERSHOT_CHROME_PATH='.$chrome);
        $_ENV['BROWSERSHOT_CHROME_PATH'] = $chrome;
        $_SERVER['BROWSERSHOT_CHROME_PATH'] = $chrome;

        $bytes = app(PdfService::class)->renderView('pdf.smoke', [
            'title' => 'TPDA e-Sheba',
            'body' => 'ক্ষ জ্ঞ ১২৩',
        ]);

        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertGreaterThan(3000, strlen($bytes));
    }

    public function test_fallback_threshold_is_200_rows(): void
    {
        $pdf = app(PdfService::class);
        $this->assertFalse($pdf->shouldUseFallback(200));
        $this->assertTrue($pdf->shouldUseFallback(201));
    }
}
