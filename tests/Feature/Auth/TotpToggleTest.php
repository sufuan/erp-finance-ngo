<?php

namespace Tests\Feature\Auth;

use App\Models\OrganizationProfile;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\TotpService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Master TOTP switch (setting security.totp_enabled, Settings → General
 * Settings → Security tab). Default OFF: nobody is forced to enroll in
 * or pass TOTP. ON: the Step 2 behavior applies unchanged — mandatory
 * roles (super-admin, branch-manager, accountant) enroll on first login
 * and pass a challenge on every login.
 */
class TotpToggleTest extends TestCase
{
    use DatabaseTransactions;

    private function superAdminWithoutTotp(): User
    {
        $user = User::factory()->create([
            'password' => Hash::make('secret-pass'),
            'must_change_password' => false,
            'totp_secret' => null,
            'totp_confirmed_at' => null,
        ]);
        $user->assignRole('super-admin');

        return $user;
    }

    private function setSwitch(bool $on): void
    {
        app(SettingsService::class)->set('security.totp_enabled', $on);
    }

    public function test_default_is_off_when_the_setting_row_is_missing(): void
    {
        Setting::where('key', 'security.totp_enabled')->delete();
        Cache::forget('settings.security.totp_enabled');

        $this->assertFalse(app(TotpService::class)->enabled());

        $admin = $this->superAdminWithoutTotp();
        $this->assertFalse(app(TotpService::class)->requiredFor($admin));

        // No enrollment/challenge redirect — the app opens straight away.
        $this->actingAs($admin)->get('/')->assertOk();
    }

    public function test_off_lets_mandatory_role_without_totp_use_the_app(): void
    {
        $this->setSwitch(false);

        $admin = $this->superAdminWithoutTotp();
        $this->actingAs($admin)->get('/')->assertOk();
    }

    public function test_off_redirects_totp_pages_and_posts_home(): void
    {
        $this->setSwitch(false);

        $admin = $this->superAdminWithoutTotp();
        $this->actingAs($admin);

        $this->get('/totp/setup')->assertRedirect(route('home'));
        $this->get('/totp/challenge')->assertRedirect(route('home'));
        $this->post('/totp/setup', ['code' => '123456'])->assertRedirect(route('home'));
        $this->post('/totp/challenge', ['code' => '123456'])->assertRedirect(route('home'));

        // Nothing was enrolled behind the scenes.
        $this->assertFalse($admin->fresh()->hasTotpEnabled());
    }

    public function test_on_forces_enrollment_for_mandatory_role_without_secret(): void
    {
        $this->setSwitch(true);

        $admin = $this->superAdminWithoutTotp();
        $this->actingAs($admin)->get('/')->assertRedirect(route('totp.setup'));
    }

    public function test_on_forces_challenge_for_mandatory_role_with_confirmed_secret(): void
    {
        $this->setSwitch(true);

        $admin = $this->superAdminWithoutTotp();
        $admin->forceFill([
            'totp_secret' => app(TotpService::class)->generateSecret(),
            'totp_confirmed_at' => now(),
        ])->save();

        $this->actingAs($admin)->get('/')->assertRedirect(route('totp.challenge'));

        // The challenge page itself renders while the switch is on.
        $this->get('/totp/challenge')->assertOk();
    }

    public function test_on_keeps_totp_optional_for_other_roles(): void
    {
        $this->setSwitch(true);

        $officer = User::factory()->create([
            'password' => Hash::make('secret-pass'),
            'must_change_password' => false,
        ]);
        $officer->assignRole('field-officer');

        $this->actingAs($officer)->get('/')->assertOk();
    }

    public function test_seeder_creates_the_switch_off_and_a_reseed_never_clobbers_it(): void
    {
        Setting::where('key', 'security.totp_enabled')->delete();
        Cache::forget('settings.security.totp_enabled');

        (new SettingsSeeder)->run();

        $row = Setting::where('key', 'security.totp_enabled')->first();
        $this->assertNotNull($row);
        $this->assertSame('security', $row->group);
        $this->assertSame('boolean', $row->type);
        $this->assertFalse($row->decodedValue());

        // The admin turns it on; re-running the seeder must not flip it back.
        app(SettingsService::class)->set('security.totp_enabled', true);
        (new SettingsSeeder)->run();

        $this->assertTrue(Setting::where('key', 'security.totp_enabled')->first()->decodedValue());
    }

    public function test_general_settings_toggle_cycle_persists_boolean(): void
    {
        // The real route, as the seeded super-admin (same gate-skipping
        // pattern as SettingsTest: password already changed, and the
        // session flag keeps the ON half of the cycle reachable).
        $admin = User::where('username', 'admin')->first();
        $admin->forceFill(['must_change_password' => false])->save();
        $this->actingAs($admin);
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);

        $profile = OrganizationProfile::active();
        $payload = fn (bool $on) => [
            'profile' => [
                'name_en' => $profile->name_en, 'name_bn' => $profile->name_bn, 'org_type' => $profile->org_type,
                'registration_no' => $profile->registration_no, 'mobile_app_name' => $profile->mobile_app_name,
                'contact_person' => $profile->contact_person,
            ],
            'settings' => ['security.totp_enabled' => $on],
        ];

        $this->post('/settings/general', $payload(true))->assertRedirect()->assertSessionHas('success');
        $this->assertTrue(Setting::where('key', 'security.totp_enabled')->first()->decodedValue());
        $this->assertTrue(app(SettingsService::class)->get('security.totp_enabled'));
        $this->assertTrue(app(TotpService::class)->enabled());

        $this->post('/settings/general', $payload(false))->assertRedirect()->assertSessionHas('success');
        $this->assertFalse(Setting::where('key', 'security.totp_enabled')->first()->decodedValue());
        $this->assertFalse(app(SettingsService::class)->get('security.totp_enabled'));
        $this->assertFalse(app(TotpService::class)->enabled());
    }
}
