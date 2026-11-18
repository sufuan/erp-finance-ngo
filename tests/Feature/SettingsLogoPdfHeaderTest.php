<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\OrganizationProfile;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\LoanProposalService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Owner order 2026-11-18: (a) the organization logo uploaded in Settings →
 * General Settings must be used everywhere — app shell, login page and a
 * common header on every PDF; (b) PDF Bangla headings must not print as
 * "????" (mPDF bold/lang font-switch bug — see PdfService).
 */
class SettingsLogoPdfHeaderTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['must_change_password' => false]);
        $this->admin->assignRole('super-admin');
        $this->actingAs($this->admin);
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);
    }

    private function settingsPayload(array $extra = []): array
    {
        $p = OrganizationProfile::active();

        return array_merge([
            'profile' => [
                'name_en' => $p->name_en, 'name_bn' => $p->name_bn,
                'org_type' => $p->org_type, 'registration_no' => $p->registration_no,
                'mobile_app_name' => $p->mobile_app_name, 'contact_person' => $p->contact_person,
                'address' => $p->address, 'phone' => $p->phone, 'email' => $p->email,
            ],
            // Same value as stored, so the settings loop writes nothing.
            'settings' => ['app.name' => 'TPDA e-Sheba'],
        ], $extra);
    }

    private function extractText(string $pdfBytes): ?string
    {
        if (! shell_exec('command -v pdftotext')) {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'pdf-test-');
        file_put_contents($tmp.'.pdf', $pdfBytes);
        $text = shell_exec('pdftotext -layout '.escapeshellarg($tmp.'.pdf').' - 2>/dev/null');
        @unlink($tmp.'.pdf');
        @unlink($tmp);

        return $text ?: null;
    }

    private function imageCount(string $pdfBytes): ?int
    {
        if (! shell_exec('command -v pdfimages')) {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'pdf-test-');
        file_put_contents($tmp.'.pdf', $pdfBytes);
        $out = shell_exec('pdfimages -list '.escapeshellarg($tmp.'.pdf').' 2>/dev/null');
        @unlink($tmp.'.pdf');
        @unlink($tmp);
        if ($out === null) {
            return null;
        }
        // Header line + column header + one line per image.
        return max(0, count(array_filter(explode("\n", trim($out)))) - 2);
    }

    public function test_logo_upload_replace_and_remove_cycle(): void
    {
        $disk = Storage::disk('private');

        // Upload through the real Settings form route.
        $this->post(route('settings.general.update'), $this->settingsPayload([
            'logo' => UploadedFile::fake()->image('logo-one.png', 120, 80),
        ]))->assertRedirect()->assertSessionHas('success');

        $profile = OrganizationProfile::active()->fresh();
        $this->assertNotNull($profile->logo_path);
        $this->assertTrue($disk->exists($profile->logo_path));
        $firstPath = $profile->logo_path;

        // The public logo route serves it (login page is a guest page).
        $this->get('/org-logo')->assertOk();

        // Replace: new file stored, old file deleted.
        $this->post(route('settings.general.update'), $this->settingsPayload([
            'logo' => UploadedFile::fake()->image('logo-two.png', 100, 100),
        ]))->assertRedirect()->assertSessionHas('success');

        $profile = OrganizationProfile::active()->fresh();
        $this->assertNotNull($profile->logo_path);
        $this->assertNotSame($firstPath, $profile->logo_path);
        $this->assertFalse($disk->exists($firstPath));
        $this->assertTrue($disk->exists($profile->logo_path));
        $secondPath = $profile->logo_path;

        // Remove: path cleared, file deleted, route back to 404.
        $this->post(route('settings.general.update'), $this->settingsPayload([
            'remove_logo' => true,
        ]))->assertRedirect()->assertSessionHas('success');

        $profile = OrganizationProfile::active()->fresh();
        $this->assertNull($profile->logo_path);
        $this->assertFalse($disk->exists($secondPath));
        $this->get('/org-logo')->assertNotFound();
    }

    public function test_non_image_logo_upload_is_rejected(): void
    {
        $this->post(route('settings.general.update'), $this->settingsPayload([
            'logo' => UploadedFile::fake()->create('not-an-image.pdf', 10, 'application/pdf'),
        ]))->assertSessionHasErrors('logo');

        $this->assertNull(OrganizationProfile::active()->fresh()->logo_path);
    }

    public function test_proposal_pdf_bangla_headings_render_and_header_carries_logo(): void
    {
        $member = Member::query()->firstOrFail();
        $product = LoanProduct::where('code', '3001')->firstOrFail();
        $proposal = app(LoanProposalService::class)->submit($member, [
            'loan_product_id' => $product->id, 'proposal_date' => '2026-10-21',
            'loan_purpose' => 'Agriculture', 'loan_amount' => '12000',
            'fdr_account_id' => null, 'frequency' => 'M', 'no_of_installments' => 12,
            'profit_rate' => null, 'installment_from_date' => '2026-11-01',
            'guarantors' => [],
        ], $this->admin);

        // No logo yet: PDF renders, Bangla headings extract as Bangla.
        // Extraction note: mPDF encodes shaped conjunct glyphs as PUA
        // codepoints, so whole shaped words never match literally — the
        // discriminating checks are the unshaped fragments (ঋণ from the
        // title, সদস from সদস্য), which are entirely ABSENT when the
        // ???? bug strikes, plus the absence of literal question marks.
        $bytes = $this->get(route('loans.proposals.report', $proposal))->assertOk()->getContent();
        $this->assertStringStartsWith('%PDF', $bytes);
        if (($text = $this->extractText($bytes)) !== null) {
            $this->assertStringContainsString('ঋণ', $text);
            $this->assertStringContainsString('সদস', $text);
            $this->assertStringNotContainsString('????', $text);
        }
        if (($count = $this->imageCount($bytes)) !== null) {
            $this->assertSame(0, $count, 'No logo uploaded, so the header must embed no image');
        }

        // Upload a logo through Settings, then the same PDF header embeds it.
        $this->post(route('settings.general.update'), $this->settingsPayload([
            'logo' => UploadedFile::fake()->image('logo.png', 120, 80),
        ]))->assertSessionHas('success');
        $logoPath = OrganizationProfile::active()->fresh()->logo_path;
        $this->assertNotNull($logoPath);

        try {
            $bytes = $this->get(route('loans.proposals.report', $proposal))->assertOk()->getContent();
            if (($count = $this->imageCount($bytes)) !== null) {
                $this->assertGreaterThanOrEqual(1, $count, 'Uploaded logo must be embedded in the PDF header');
            }
            if (($text = $this->extractText($bytes)) !== null) {
                $this->assertStringContainsString('ঋণ', $text);
                $this->assertStringNotContainsString('????', $text);
            }
        } finally {
            Storage::disk('private')->delete($logoPath);
        }
    }

    public function test_director_pdf_bangla_renders(): void
    {
        $director = User::factory()->create(['must_change_password' => false]);
        $director->assignRole('director');
        $this->actingAs($director);
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);

        $bytes = $this->get('/director/pdf')->assertOk()->getContent();
        $this->assertStringStartsWith('%PDF', $bytes);
        if (($text = $this->extractText($bytes)) !== null) {
            $this->assertStringNotContainsString('????', $text);
            // director.today label in Bangla locale is আজ (the app default locale is bn in tests via seeded settings).
            $this->assertStringContainsString('তালগাছী', $text);
        }
    }
}
