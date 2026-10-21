<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Setting;
use App\Models\User;
use App\Services\BusinessDayService;
use App\Services\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Auto Closing of the business day (owner feature): the toggle on the
 * Business Day page, the `business_day.auto_close` setting behind it,
 * and the nightly `business-day:auto-close` scheduler command.
 */
class BusinessDayAutoCloseTest extends TestCase
{
    use DatabaseTransactions;

    private Branch $head;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->head = Branch::firstOrCreate(['code' => '101'], ['name' => 'Head Office', 'type' => 'Head Office', 'status' => 'Active']);
        $this->branch = Branch::firstOrCreate(['code' => '102'], ['name' => 'Talgachi Branch', 'type' => 'Branch', 'parent_branch_id' => $this->head->id, 'status' => 'Active']);
    }

    /** Simulate an install seeded before this feature existed: no row. */
    private function removeSettingRow(): void
    {
        Setting::where('key', 'business_day.auto_close')->delete();
        Cache::forget('settings.business_day.auto_close');
    }

    public function test_command_with_setting_off_leaves_business_dates_unchanged(): void
    {
        $this->removeSettingRow();
        $settings = app(SettingsService::class);
        // Reads fall back to the default (off) when no row exists.
        $this->assertFalse((bool) $settings->get('business_day.auto_close', false));

        $days = app(BusinessDayService::class);
        $headBefore = $days->currentDate($this->head->id);
        $branchBefore = $days->currentDate($this->branch->id);

        $this->artisan('business-day:auto-close')->assertSuccessful();

        $this->assertSame($headBefore, $days->currentDate($this->head->id));
        $this->assertSame($branchBefore, $days->currentDate($this->branch->id));
        $this->assertSame(0, AuditLog::where('action', 'business_day.auto_closed')->count());
    }

    public function test_command_with_setting_on_advances_every_branch_and_audits(): void
    {
        app(SettingsService::class)->set('business_day.auto_close', true);

        $days = app(BusinessDayService::class);
        $headBefore = $days->currentDate($this->head->id);
        $branchBefore = $days->currentDate($this->branch->id);

        $this->artisan('business-day:auto-close')->assertSuccessful();

        $this->assertSame(
            CarbonImmutable::parse($headBefore)->addDay()->toDateString(),
            $days->currentDate($this->head->id)
        );
        $this->assertSame(
            CarbonImmutable::parse($branchBefore)->addDay()->toDateString(),
            $days->currentDate($this->branch->id)
        );

        foreach ([$this->head, $this->branch] as $branch) {
            $row = AuditLog::where('action', 'business_day.auto_closed')
                ->where('entity', 'branches')
                ->where('entity_id', $branch->id)
                ->latest('id')
                ->first();
            $this->assertNotNull($row, "Expected an auto_closed audit row for branch {$branch->id}");
            $this->assertNull($row->user_id); // system actor, not a user
        }
    }

    public function test_toggle_endpoint_flips_setting_and_requires_closing_permission(): void
    {
        $this->removeSettingRow();

        // A signed-in user without the closing permission is refused,
        // and the setting is not created as a side effect.
        $plain = User::factory()->create(['must_change_password' => false]);
        $this->actingAs($plain);
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);
        $this->post('/settings/business-day/auto-close', ['enabled' => true])->assertForbidden();
        $this->assertFalse((bool) app(SettingsService::class)->get('business_day.auto_close', false));

        // Super admin (holds settings.closing) can flip it both ways;
        // the first save creates the settings row on the fly.
        $admin = User::factory()->create(['must_change_password' => false]);
        $admin->assignRole('super-admin');
        $this->actingAs($admin);
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);

        $this->post('/settings/business-day/auto-close', ['enabled' => true])->assertRedirect();
        $this->assertTrue((bool) app(SettingsService::class)->get('business_day.auto_close', false));
        $this->assertSame('boolean', Setting::where('key', 'business_day.auto_close')->value('type'));

        $this->post('/settings/business-day/auto-close', ['enabled' => false])->assertRedirect();
        $this->assertFalse((bool) app(SettingsService::class)->get('business_day.auto_close', false));
    }

    public function test_business_day_page_shows_auto_close_state(): void
    {
        $admin = User::factory()->create(['must_change_password' => false]);
        $admin->assignRole('super-admin');
        $this->actingAs($admin);
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);

        app(SettingsService::class)->set('business_day.auto_close', true);

        $this->get('/settings/business-day')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/BusinessDay/Index')
                ->where('autoClose', true)
            );
    }
}
