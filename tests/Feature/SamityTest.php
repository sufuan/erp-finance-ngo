<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Notice;
use App\Models\Samity;
use App\Models\SamityDayChange;
use App\Models\SamityOfficerChange;
use App\Models\User;
use App\Services\SamityAccessService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Plan Step 7 acceptance: opening, changes, holidays, notices, scope. */
class SamityTest extends TestCase
{
    use DatabaseTransactions;

    private function manager(): User
    {
        $user = User::factory()->create(['must_change_password' => false]);
        $user->assignRole('branch-manager');
        $this->actingAs($user);
        // Branch Manager is a TOTP-mandatory role; simulate a passed challenge.
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);

        return $user;
    }

    private function payload(array $overrides = []): array
    {
        $branch = Branch::where('code', '102')->first();

        return array_merge([
            'branch_id' => $branch->id,
            'name' => 'New Test Samity',
            'open_date' => '2024-02-01',
            'center_type' => 'All Gender',
            'samity_day' => 'Monday',
            'latitude' => '24.3065',
            'longitude' => '89.5733',
        ], $overrides);
    }

    public function test_samities_are_seeded_with_four_digit_codes(): void
    {
        $this->assertSame(2, Samity::withoutGlobalScopes()->count());
        foreach (Samity::withoutGlobalScopes()->get() as $samity) {
            $this->assertMatchesRegularExpression('/^\d{4}$/', $samity->samity_code);
        }
    }

    public function test_open_samity_generates_code_and_persists_location(): void
    {
        $this->manager();
        $this->post('/settings/samities', $this->payload())->assertSessionHas('success');
        $samity = Samity::where('name', 'New Test Samity')->first();
        $this->assertNotNull($samity);
        $this->assertMatchesRegularExpression('/^\d{4}$/', $samity->samity_code);
        $this->assertSame('24.3065000', (string) $samity->latitude);
    }

    public function test_officer_change_records_history_and_updates_current(): void
    {
        $this->manager();
        $samity = Samity::where('name', 'Talgachi Samity')->first();
        $oldOfficer = $samity->field_officer_id;
        $newOfficer = Employee::where('mobile', '01614444444')->first();

        $this->post('/settings/samities/changes/officer', [
            'samity_id' => $samity->id, 'new_value' => $newOfficer->id,
            'change_date' => '2026-10-21', 'effective_from' => '2026-10-26', 'comments' => 'Rotation',
        ])->assertSessionHas('success');

        $this->assertSame($newOfficer->id, $samity->fresh()->field_officer_id);
        $change = SamityOfficerChange::where('samity_id', $samity->id)->first();
        $this->assertSame((string) $oldOfficer, $change->previous_value);
        $this->assertSame((string) $newOfficer->id, $change->new_value);
        $this->assertSame('Rotation', $change->comments);
    }

    public function test_day_change_records_history(): void
    {
        $this->manager();
        $samity = Samity::where('name', 'Talgachi Samity')->first();
        $this->post('/settings/samities/changes/day', [
            'samity_id' => $samity->id, 'new_value' => 'Tuesday',
            'change_date' => '2026-10-21', 'effective_from' => '2026-10-27',
        ])->assertSessionHas('success');
        $this->assertSame('Tuesday', $samity->fresh()->samity_day);
        $this->assertSame('Wednesday', SamityDayChange::first()->previous_value);
    }

    public function test_holiday_range_creates_one_row_per_day_and_skips_existing(): void
    {
        $this->manager();
        $branch = Branch::where('code', '102')->first();
        $data = [
            'branch_id' => $branch->id, 'details' => 'Eid Holiday',
            'date_from' => '2026-11-01', 'date_to' => '2026-11-03',
        ];
        $this->post('/settings/holidays', $data)->assertSessionHas('success');
        $this->assertSame(3, Holiday::where('details', 'Eid Holiday')->count());
        // Re-posting the same range creates nothing new.
        $this->post('/settings/holidays', $data)->assertSessionHas('success');
        $this->assertSame(3, Holiday::where('details', 'Eid Holiday')->count());
    }

    public function test_holiday_range_over_31_days_rejected(): void
    {
        $this->manager();
        $branch = Branch::where('code', '102')->first();
        $this->post('/settings/holidays', [
            'branch_id' => $branch->id, 'details' => 'Too Long',
            'date_from' => '2026-11-01', 'date_to' => '2026-12-15',
        ])->assertSessionHasErrors(['date_to']);
    }

    public function test_notice_is_shared_to_topbar(): void
    {
        $this->manager();
        $this->post('/settings/notices', ['text' => 'Office closed Friday', 'text_color' => 'Red'])
            ->assertSessionHas('success');
        $notice = Notice::first();
        $this->assertSame('Red', $notice->text_color);
        $this->get('/settings/samities')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('notice.text', 'Office closed Friday')->where('notice.color', 'Red'));
    }

    public function test_field_officer_scope_limits_samity_list_and_blocks_unassigned(): void
    {
        $samities = Samity::withoutGlobalScopes()->orderBy('id')->get();
        [$assigned, $other] = [$samities[0], $samities[1]];

        $officer = User::factory()->create(['must_change_password' => false]);
        $officer->assignRole('field-officer');
        $officer->givePermissionTo('settings.view');
        DB::table('samity_user')->insert([
            'user_id' => $officer->id, 'samity_id' => $assigned->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($officer);

        // Query-level scope: only the assigned samity is visible.
        $visible = Samity::pluck('id')->all();
        $this->assertSame([$assigned->id], $visible);

        // Direct access guard: unassigned samity → 403, assigned → OK.
        $access = app(SamityAccessService::class);
        $this->assertTrue($access->canAccess($officer, $assigned->id));
        $this->assertFalse($access->canAccess($officer, $other->id));
        $this->get('/settings/samities/changes/day?samity_id='.$other->id)->assertForbidden();
        $this->get('/settings/samities/changes/day?samity_id='.$assigned->id)->assertOk();
    }

    public function test_merge_closes_source_and_audits(): void
    {
        $this->manager();
        [$source, $target] = Samity::withoutGlobalScopes()->orderBy('id')->get();
        $this->post('/settings/samities/merge', [
            'source_samity_id' => $source->id, 'target_samity_id' => $target->id,
        ])->assertSessionHas('success');
        $this->assertSame('Merged', $source->fresh()->status);
        $this->assertNotNull($source->fresh()->closed_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'samity.merged', 'entity' => 'samities', 'entity_id' => $source->id]);
    }

    public function test_merge_across_branches_rejected(): void
    {
        $this->manager();
        $branch = Branch::where('code', '101')->first();
        $other = Samity::create([
            'samity_code' => '9001', 'branch_id' => $branch->id, 'name' => 'HO Samity',
            'open_date' => '2024-01-01', 'center_type' => 'All Gender', 'samity_day' => 'Sunday', 'status' => 'Active',
        ]);
        $source = Samity::withoutGlobalScopes()->first();
        $this->post('/settings/samities/merge', [
            'source_samity_id' => $source->id, 'target_samity_id' => $other->id,
        ])->assertSessionHasErrors(['target_samity_id']);
    }
}
