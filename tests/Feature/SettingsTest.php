<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\OrganizationProfile;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Plan Step 3 acceptance: TPDA profile + settings seeded exactly, changes
 * apply immediately (cache bust), audited, permission-guarded.
 */
class SettingsTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        $admin = User::where('username', 'admin')->first();
        // The seeded admin must change password + enroll TOTP on first real
        // login (covered in RbacTest); here we skip both gates directly.
        $admin->forceFill(['must_change_password' => false])->save();
        $this->actingAs($admin);
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);

        return $admin;
    }

    public function test_tpda_profile_is_seeded_exactly(): void
    {
        $profile = OrganizationProfile::active();
        $this->assertSame('Talgachi Poor Development Association (T.P.D.A.)', $profile->name_en);
        $this->assertSame('তালগাছী দরিদ্র উন্নয়ন সংস্থা', $profile->name_bn);
        $this->assertSame('Non-Governmental Organization (NGO)', $profile->org_type);
        $this->assertSame('সিরাজ/৫৮৫/সমাজসেবা/২০০০', $profile->registration_no);
        $this->assertSame('TPDA e-Sheba', $profile->mobile_app_name);
        $this->assertSame('Md. Mostafa', $profile->contact_person);
    }

    public function test_settings_are_seeded_with_plan_values(): void
    {
        $settings = app(SettingsService::class);
        $this->assertSame('TPDA e-Sheba', $settings->get('app.name'));
        $this->assertSame('en', $settings->get('app.locale_default'));
        $this->assertSame(15, $settings->get('loan.default_profit_rate'));
        $this->assertSame(24, $settings->get('loan.max_service_charge_rate'));
        $this->assertSame(['standard' => 1, 'watchlist' => 5, 'substandard' => 25, 'doubtful' => 75, 'loss' => 100], $settings->get('llp.rates'));
        $this->assertSame('net_outstanding', $settings->get('llp.basis'));
        $this->assertSame(['penalty', 'profit', 'principal'], $settings->get('loan.allocation_order'));
        $this->assertTrue($settings->get('loan.allow_overpayment'));
        $this->assertSame(['D', 'W', 'M'], $settings->get('loan.enabled_frequencies'));
        $this->assertSame(50000, $settings->get('accounts.auth_amount_threshold'));
        $this->assertTrue($settings->get('gps.enabled'));
        $this->assertSame(60, $settings->get('gps.interval_seconds'));
        $this->assertSame(7, $settings->get('app.max_offline_days'));
        $this->assertSame('arabic', $settings->get('locale.number_system'));
        $this->assertSame('d-M-Y', $settings->get('locale.date_format'));
    }

    public function test_set_busts_the_cache_so_changes_apply_immediately(): void
    {
        $settings = app(SettingsService::class);
        $this->assertSame(15, $settings->get('loan.default_profit_rate')); // warm the cache
        $this->assertTrue(Cache::has('settings.loan.default_profit_rate'));

        $settings->set('loan.default_profit_rate', 16);
        $this->assertSame(16, app(SettingsService::class)->get('loan.default_profit_rate'));
        $this->assertSame(16, \App\Models\Setting::where('key', 'loan.default_profit_rate')->first()->decodedValue());
    }

    public function test_general_settings_page_requires_settings_general_permission(): void
    {
        $officer = User::factory()->create(['must_change_password' => false]);
        $officer->assignRole('field-officer');
        $this->actingAs($officer)->get('/settings/general')->assertForbidden();

        $this->admin();
        $this->get('/settings/general')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Settings/General/Index')->has('profile')->has('settings'));
    }

    public function test_general_settings_page_props_contain_every_sections_data(): void
    {
        $this->admin();

        // Every key the seven tabs (organization, application, loan, deposit,
        // sms, gps, security) read must be present in the props with the right shape —
        // a missing or string-shaped list setting is what white-screened
        // this page in the browser.
        $settingKeys = [
            'app.name', 'app.locale_default', 'locale.number_system', 'locale.date_format',
            'print.header_lines', 'print.footer_text',
            'loan.default_profit_rate', 'loan.max_service_charge_rate', 'loan.allocation_order',
            'loan.allow_overpayment', 'loan.enabled_frequencies', 'loan.fee_defaults',
            'llp.rates', 'llp.basis', 'profit.tax_withholding_rate', 'accounts.auth_amount_threshold',
            'deposit.default_profit_rates', 'deposit.closing_charge',
            'sms.sender_id', 'sms.gateway', 'sms.enabled',
            'gps.enabled', 'gps.interval_seconds', 'gps.retention_days', 'app.max_offline_days',
            'security.totp_enabled',
        ];

        $response = $this->get('/settings/general')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Settings/General/Index')->has('profile')->has('settings'));

        // Setting keys literally contain dots, so assert on the raw props
        // array rather than Inertia's dot-path assertions.
        $props = $response->viewData('page')['props'];
        foreach (['name_en', 'name_bn', 'org_type', 'registration_no', 'mobile_app_name', 'contact_person'] as $field) {
            $this->assertArrayHasKey($field, $props['profile'], "profile.{$field} missing");
        }
        foreach ($settingKeys as $key) {
            $this->assertArrayHasKey($key, $props['settings'], "settings key {$key} missing");
        }
        $s = $props['settings'];
        $this->assertSame(['penalty', 'profit', 'principal'], $s['loan.allocation_order']);
        $this->assertSame(['D', 'W', 'M'], $s['loan.enabled_frequencies']);
        $this->assertIsArray($s['print.header_lines']);
        $this->assertNotEmpty($s['print.header_lines']);
        foreach (['form', 'health', 'risk', 'document', 'emi_locker', 'others'] as $fee) {
            $this->assertArrayHasKey($fee, $s['loan.fee_defaults'], "fee_defaults.{$fee} missing");
        }
        foreach (['standard', 'watchlist', 'substandard', 'doubtful', 'loss'] as $class) {
            $this->assertArrayHasKey($class, $s['llp.rates'], "llp.rates.{$class} missing");
        }
        foreach (['savings', 'dps', 'fdr'] as $kind) {
            $this->assertArrayHasKey($kind, $s['deposit.default_profit_rates'], "default_profit_rates.{$kind} missing");
        }
    }

    public function test_general_settings_page_survives_double_encoded_json_settings(): void
    {
        $this->admin();

        // Regression: a list/object setting stored double-encoded (a JSON
        // string wrapping the real JSON) used to reach the page as a plain
        // string; the form initializer called .join() on it, React threw,
        // and the whole app went white. The decoder must unwrap it.
        foreach (['loan.allocation_order', 'print.header_lines', 'loan.fee_defaults'] as $key) {
            $setting = \App\Models\Setting::where('key', $key)->first();
            $setting->forceFill(['value' => json_encode(json_encode($setting->decodedValue()))])->save();
        }
        Cache::flush();

        $settings = app(SettingsService::class);
        $this->assertSame(['penalty', 'profit', 'principal'], $settings->get('loan.allocation_order'));
        $this->assertIsArray($settings->get('print.header_lines'));
        $this->assertIsArray($settings->get('loan.fee_defaults'));

        $response = $this->get('/settings/general')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Settings/General/Index')->has('settings'));
        $s = $response->viewData('page')['props']['settings'];
        $this->assertSame(['penalty', 'profit', 'principal'], $s['loan.allocation_order']);
        $this->assertIsArray($s['print.header_lines']);
        $this->assertNotEmpty($s['print.header_lines']);
        $this->assertArrayHasKey('form', $s['loan.fee_defaults']);
    }

    public function test_updating_settings_via_ui_changes_app_name_and_audits(): void
    {
        $admin = $this->admin();
        $profile = OrganizationProfile::active();

        $this->post('/settings/general', [
            'profile' => [
                'name_en' => $profile->name_en, 'name_bn' => $profile->name_bn, 'org_type' => $profile->org_type,
                'registration_no' => $profile->registration_no, 'mobile_app_name' => $profile->mobile_app_name,
                'contact_person' => $profile->contact_person, 'address' => 'Talgachi, Shahjadpur, Sirajganj',
                'phone' => '01700000000', 'email' => 'info@tpda.example',
            ],
            'settings' => ['app.name' => 'TPDA e-Sheba Pro', 'loan.default_profit_rate' => 16],
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame('TPDA e-Sheba Pro', app(SettingsService::class)->get('app.name'));
        $this->assertSame(16, app(SettingsService::class)->get('loan.default_profit_rate'));
        $this->assertSame('Talgachi, Shahjadpur, Sirajganj', OrganizationProfile::active()->address);

        $audit = AuditLog::where('action', 'settings.updated')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame($admin->id, $audit->user_id);

        // The shared appName prop follows the setting immediately.
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('appName', 'TPDA e-Sheba Pro'));
    }

    public function test_allocation_order_saves_as_array_in_posted_order(): void
    {
        $this->admin();
        $profile = OrganizationProfile::active();

        // The reorder control submits loan.allocation_order as a real array
        // (never a comma-typed string); the stored setting must decode to
        // exactly the posted permutation — LoanMoneyService consumes it
        // as-is when splitting a received installment.
        $this->post('/settings/general', [
            'profile' => [
                'name_en' => $profile->name_en, 'name_bn' => $profile->name_bn, 'org_type' => $profile->org_type,
                'registration_no' => $profile->registration_no, 'mobile_app_name' => $profile->mobile_app_name,
                'contact_person' => $profile->contact_person,
            ],
            'settings' => ['loan.allocation_order' => ['principal', 'penalty', 'profit']],
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(['principal', 'penalty', 'profit'], app(SettingsService::class)->get('loan.allocation_order'));

        // …and the settings page hands that exact order back to the form.
        $response = $this->get('/settings/general')->assertOk();
        $s = $response->viewData('page')['props']['settings'];
        $this->assertSame(['principal', 'penalty', 'profit'], $s['loan.allocation_order']);
    }

    public function test_logo_upload_stores_file_and_sets_logo_path(): void
    {
        $this->admin();
        $profile = OrganizationProfile::active();

        // A real 1x1 PNG: UploadService re-encodes images through GD, so
        // an empty/faked file would be rejected as "not a valid image".
        $tmp = tempnam(sys_get_temp_dir(), 'logo').'.png';
        file_put_contents($tmp, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $logo = new UploadedFile($tmp, 'logo.png', 'image/png', null, true);

        // Regression: this used to 500 — UploadService had no 'logo' type,
        // and the controller misread the returned path string as an array.
        $this->post('/settings/general', [
            'profile' => [
                'name_en' => $profile->name_en, 'name_bn' => $profile->name_bn, 'org_type' => $profile->org_type,
                'registration_no' => $profile->registration_no, 'mobile_app_name' => $profile->mobile_app_name,
                'contact_person' => $profile->contact_person,
            ],
            'logo' => $logo,
            'settings' => ['app.name' => 'TPDA e-Sheba'],
        ])->assertRedirect()->assertSessionHas('success');

        $profile->refresh();
        $this->assertNotNull($profile->logo_path);
        $this->assertStringStartsWith('logos/', $profile->logo_path);
        $this->assertTrue(Storage::disk('private')->exists($profile->logo_path));

        // The settings page renders /files/{logo_path} — it must serve.
        $this->get('/files/'.$profile->logo_path)->assertOk();

        Storage::disk('private')->delete($profile->logo_path);
        @unlink($tmp);
    }
}
