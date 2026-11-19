<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BusinessDay;
use App\Models\DepositProduct;
use App\Models\LoanProduct;
use App\Models\Member;
use App\Models\OrganizationProfile;
use App\Models\Samity;
use App\Models\User;
use App\Services\BusinessDayService;
use App\Services\DepositAccountService;
use App\Services\DepositTransactionService;
use App\Services\LoanMoneyService;
use App\Services\LoanOpeningService;
use App\Services\PdfService;
use App\Services\RegisterReportService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression (live defect, 2026-11-19): the two register PDFs came
 * back blank/broken from the live host, where every render takes the
 * mPDF path (no Chromium there):
 *
 *  - the daily register rendered A4 PORTRAIT at 0.000pt fonts — the
 *    blade's `@page { size: A4 landscape }` does not change mPDF's
 *    page format, and the unconstrained 25-column table was shrunk
 *    to invisibility by mPDF's shrink-to-fit;
 *  - the weekly sheet rendered 141 mostly-blank pages — a `size:`
 *    declaration inside @page sends mPDF into runaway pagination
 *    (a bare "Hello" under that rule renders 14 pages).
 *
 * Fixes covered here: PdfService takes an explicit format/orientation
 * (the daily register requests A4 landscape) and strips @page blocks
 * before WriteHTML; the daily blade pins its 25 columns with a fixed
 * layout + colgroup. These tests force the mPDF path (rowCount above
 * the fallback threshold) and inspect the produced bytes: page
 * geometry, page count, and the content-stream font-size histogram.
 */
class RegisterPdfRenderTest extends TestCase
{
    use DatabaseTransactions;

    private Branch $branch;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['must_change_password' => false]);
        $this->admin->assignRole('super-admin');
        $this->branch = Branch::create([
            'code' => '902', 'name' => 'Register PDF Branch', 'type' => 'Branch',
            'opening_date' => '2026-11-01', 'country' => 'Bangladesh', 'status' => 'Active',
        ]);
        BusinessDay::create([
            'branch_id' => $this->branch->id, 'business_date' => '2026-11-01', 'is_closed' => false,
        ]);
        $one = Samity::create([
            'samity_code' => '9101', 'name' => 'PDF Samity One',
            'branch_id' => $this->branch->id, 'status' => 'Active', 'open_date' => '2026-11-01',
        ]);
        $two = Samity::create([
            'samity_code' => '9102', 'name' => 'PDF Samity Two',
            'branch_id' => $this->branch->id, 'status' => 'Active', 'open_date' => '2026-11-01',
        ]);
        $this->asAdmin();
        $this->runScenario($one, $two);
    }

    private function asAdmin(): void
    {
        $this->actingAs($this->admin);
        $this->withSession([
            'active_branch_id' => $this->branch->id,
            'totp_passed_at' => now()->toDateTimeString(),
        ]);
    }

    private function admit(Samity $samity, string $mobile, int $share): Member
    {
        $this->asAdmin();
        $this->post('/members', [
            'branch_id' => $this->branch->id, 'samity_id' => $samity->id,
            'name' => 'PDF Member '.$mobile,
            'gender' => 'Female', 'mobile' => $mobile, 'national_id' => '19987'.substr($mobile, -8),
            'share_amount' => $share, 'open_date' => '2026-11-01',
            'nominee_name' => 'Nominee', 'nominee_dob' => '1990-01-01', 'nominee_relation' => 'Sister',
        ])->assertRedirect('/members/admission');

        return Member::withoutGlobalScopes()->where('mobile', $mobile)->firstOrFail();
    }

    private function runScenario(Samity $one, Samity $two): void
    {
        $savings = DepositProduct::where('code', 'SAVINGS')->firstOrFail();
        $txns = app(DepositTransactionService::class);

        // 11-01: two members, one big savings receive, one loan out.
        $m1 = $this->admit($one, '01718000101', 500);
        $m2 = $this->admit($two, '01718000102', 300);
        $account = app(DepositAccountService::class)->open($m1, $savings, [
            'opening_date' => '2026-11-01', 'installment_amount' => 100,
        ], $this->admin->id);
        $txns->receive($account, '100000', null, 'Opening deposit', $this->admin);

        $product = LoanProduct::where('code', '3001')->firstOrFail();
        $loan = app(LoanOpeningService::class)->open([
            'member_id' => $m2->id, 'employee_id' => null, 'loan_proposal_id' => null,
            'loan_product_id' => $product->id, 'opening_date' => '2026-11-01',
            'loan_purpose' => 'Agriculture', 'loan_amount' => '12000', 'down_payment' => 0,
            'frequency' => 'M', 'no_of_installments' => 12, 'profit_rate' => null,
            'installment_from_date' => '2026-11-03', 'fees' => ['insurance' => '120'], 'guarantors' => [],
        ], $this->admin);
        $loan = app(LoanMoneyService::class)->disburse($loan, $this->admin);
        $loan->update(['disbursed_at' => '2026-11-01 10:00:00']);

        // 11-02: another receive. 11-03: first installment in.
        // 11-04: a withdrawal. Then idle days to 11-08 (two week blocks).
        $days = app(BusinessDayService::class);
        $days->close($this->branch->id, $this->admin->id);
        $txns->receive($account, '20000', null, 'Weekly deposit', $this->admin);
        $days->close($this->branch->id, $this->admin->id);
        app(LoanMoneyService::class)->receive($loan->fresh(), ['amount' => '1083.10'], $this->admin);
        $days->close($this->branch->id, $this->admin->id);
        $txns->payment($account, '10000', null, 'Withdrawal', $this->admin);
        $days->close($this->branch->id, $this->admin->id);
        $days->close($this->branch->id, $this->admin->id);
        $days->close($this->branch->id, $this->admin->id);
        $days->close($this->branch->id, $this->admin->id);
    }

    // --- byte inspection helpers -------------------------------------------

    private function pageCount(string $bytes): int
    {
        return preg_match_all('/\/MediaBox\s*\[/', $bytes);
    }

    /** @return array{0: float, 1: float} width, height of the first page */
    private function firstPageSize(string $bytes): array
    {
        $this->assertSame(1, preg_match('/\/MediaBox\s*\[\s*[\d.]+\s+[\d.]+\s+([\d.]+)\s+([\d.]+)\s*\]/', $bytes, $m), 'PDF carries a MediaBox');

        return [(float) $m[1], (float) $m[2]];
    }

    /** @return array<string, int> font size (pt) => op count, from all content streams */
    private function fontHistogram(string $bytes): array
    {
        $hist = [];
        preg_match_all('/stream\r?\n(.*?)endstream/s', $bytes, $streams);
        foreach ($streams[1] as $raw) {
            $content = @gzuncompress($raw);
            if ($content === false) {
                $content = $raw;
            }
            if (preg_match_all('/\/F\d+\s+([\d.]+)\s+Tf/', $content, $ops)) {
                foreach ($ops[1] as $size) {
                    $hist[$size] = ($hist[$size] ?? 0) + 1;
                }
            }
        }

        return $hist;
    }

    private function assertNoCollapsedFonts(string $bytes): void
    {
        $hist = $this->fontHistogram($bytes);
        $total = array_sum($hist);
        $this->assertGreaterThan(0, $total, 'PDF content streams draw text');
        $collapsed = ($hist['0'] ?? 0) + ($hist['0.000'] ?? 0) + ($hist['0.00'] ?? 0);
        $this->assertSame(0, $collapsed, 'No text drawn at 0.000pt (shrink-to-fit collapse)');
    }

    // --- the mPDF path, forced ----------------------------------------------

    public function test_daily_register_mpdf_is_landscape_and_legible(): void
    {
        $data = app(RegisterReportService::class)->dailyRegister($this->branch->id, '2026-11');
        $this->assertCount(2, $data['weeks'], 'Scenario spans two week blocks');
        $html = view('reports.manager-register', [
            'org' => OrganizationProfile::first(), 'branch' => $this->branch, 'month' => '2026-11',
            'weeks' => $data['weeks'], 'grand' => $data['grand'],
        ])->render();

        $bytes = app(PdfService::class)->renderHtmlWithFallback($html, 999, 'A4', 'L');

        $this->assertStringStartsWith('%PDF', $bytes);
        [$w, $h] = $this->firstPageSize($bytes);
        $this->assertGreaterThan($h, $w, 'Daily register page is landscape');
        $this->assertEqualsWithDelta(841.89, $w, 2.0, 'A4 landscape width');
        $pages = $this->pageCount($bytes);
        $this->assertGreaterThanOrEqual(1, $pages);
        $this->assertLessThanOrEqual(3, $pages, 'A month of the register fits a few landscape pages, not a portrait stack');
        $this->assertNoCollapsedFonts($bytes);
    }

    public function test_weekly_balance_mpdf_stays_on_one_sheet(): void
    {
        $data = app(RegisterReportService::class)->weeklyBalance($this->branch->id, '2026-11-07');
        $html = view('reports.weekly-balance', [
            'org' => OrganizationProfile::first(), 'branch' => $this->branch, 'week' => $data['week'],
            'savings' => $data['savings'], 'savingsTotal' => $data['savings_total'],
            'loans' => $data['loans'], 'loansTotal' => $data['loans_total'],
        ])->render();

        $bytes = app(PdfService::class)->renderHtmlWithFallback($html, 999);

        $this->assertStringStartsWith('%PDF', $bytes);
        [$w, $h] = $this->firstPageSize($bytes);
        $this->assertGreaterThan($w, $h, 'Weekly sheet page is portrait');
        $this->assertLessThanOrEqual(2, $this->pageCount($bytes), 'Weekly sheet must not explode into a page-per-line stack');
        $this->assertNoCollapsedFonts($bytes);
    }

    // --- the real routes ------------------------------------------------------

    public function test_register_pdf_routes_serve_pdfs(): void
    {
        $daily = $this->get('/reports/manager-register/pdf?branch_id='.$this->branch->id.'&month=2026-11');
        $daily->assertOk();
        $this->assertStringStartsWith('%PDF', $daily->getContent());
        [$w, $h] = $this->firstPageSize($daily->getContent());
        $this->assertGreaterThan($h, $w, 'Daily register route serves a landscape PDF');

        $weekly = $this->get('/reports/weekly-balance/pdf?branch_id='.$this->branch->id.'&week_end=2026-11-07');
        $weekly->assertOk();
        $this->assertStringStartsWith('%PDF', $weekly->getContent());
        $this->assertLessThanOrEqual(2, $this->pageCount($weekly->getContent()));
    }

    // --- the uploaded-logo sizing (live defect, 2026-11-19) -------------------

    /**
     * The live org logo is a large square image. The register blade and
     * the shared report header sized it with CSS only (height /
     * max-width) — mPDF, the only engine that can run on the live
     * host, does not honour CSS sizing on images and printed the logo
     * at natural size, swallowing most of the daily register's page
     * (the header grew to ~60% of the page height, the 25-column
     * table squeezed into a strip below). The img tags now carry an
     * explicit height attribute, which mPDF does honour. This test
     * pins the attribute in the rendered HTML and re-checks the page
     * geometry with a 1000x1000 logo in place.
     */
    public function test_register_pdf_with_large_logo_stays_legible(): void
    {
        $img = imagecreatetruecolor(1000, 1000);
        imagefill($img, 0, 0, imagecolorallocate($img, 0, 77, 51));
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        imagedestroy($img);
        Storage::disk('private')->put('logos/test-logo.png', $png);
        $org = OrganizationProfile::first();
        $org->logo_path = 'logos/test-logo.png';
        $org->save();

        $data = app(RegisterReportService::class)->dailyRegister($this->branch->id, '2026-11');
        $html = view('reports.manager-register', [
            'org' => $org, 'branch' => $this->branch, 'month' => '2026-11',
            'weeks' => $data['weeks'], 'grand' => $data['grand'],
        ])->render();
        $this->assertMatchesRegularExpression('/<img class="logo" height="40"/', $html);

        $bytes = app(PdfService::class)->renderHtmlWithFallback($html, 999, 'A4', 'L');
        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertLessThanOrEqual(3, $this->pageCount($bytes));
        $this->assertNoCollapsedFonts($bytes);

        $partial = view('reports.partials.header', ['org' => $org, 'title' => 'Test'])->render();
        $this->assertMatchesRegularExpression('/<img class="pdf-head-logo" height="44"/', $partial);
    }

    /**
     * Universal header on the weekly sheet (owner order 2026-11-19):
     * the weekly balance sheet used to open with two ad-hoc lines and
     * no org header; it now carries the same 3-cell header as every
     * other PDF — Settings logo, org lines, navy title bar — above
     * the client's sheet body, which is unchanged.
     */
    public function test_weekly_balance_html_carries_the_universal_header(): void
    {
        $data = app(RegisterReportService::class)->weeklyBalance($this->branch->id, '2026-11-07');
        $html = view('reports.weekly-balance', [
            'org' => OrganizationProfile::first(), 'branch' => $this->branch, 'week' => $data['week'],
            'savings' => $data['savings'], 'savingsTotal' => $data['savings_total'],
            'loans' => $data['loans'], 'loansTotal' => $data['loans_total'],
        ])->render();

        $this->assertStringContainsString('table class="pdf-head"', $html);
        $this->assertStringContainsString('তালগাছী দারিদ্র উন্নয়ন সংস্থা (T.P.D.A)', $html);
        $this->assertStringContainsString('সাপ্তাহিক স্থিতির হিসাবের সিট', $html);
        $this->assertStringContainsString('কার্যালয়ঃ '.$this->branch->name, $html);
        // The sheet body below the header is untouched.
        $this->assertStringContainsString('সঞ্চয় হিসাবঃ', $html);
        $this->assertStringContainsString('কিস্তি হিসাবঃ', $html);
        $this->assertStringContainsString('মন্তব্য', $html);
    }
}
