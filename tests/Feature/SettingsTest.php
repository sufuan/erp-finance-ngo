<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\OrganizationProfile;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
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
        $this->assertSame('bn', $settings->get('app.locale_default'));
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
}
