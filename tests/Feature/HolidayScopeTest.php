<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Holiday;
use App\Models\User;
use App\Services\ScheduleEngine;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Holiday scope (owner order 2026-11-18): a holiday is created either for
 * ALL branches at once (branch_id NULL row) or branch-wise, as before.
 * The honoured-check path is ScheduleEngine::shiftToWorkingDay — the same
 * one loan/deposit schedule generation uses.
 */
class HolidayScopeTest extends TestCase
{
    use DatabaseTransactions;

    /** Sunday 2026-12-27 is a working day (BD weekend is Fri/Sat). */
    private const HOLIDAY_SUNDAY = '2026-12-27';

    private const NEXT_WORKING = '2026-12-28'; // Monday

    private function manager(): User
    {
        $user = User::factory()->create(['must_change_password' => false]);
        $user->assignRole('branch-manager');
        $this->actingAs($user);
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);

        return $user;
    }

    private function branch(string $code): Branch
    {
        return Branch::where('code', $code)->firstOrFail();
    }

    private function shifted(string $date, ?int $branchId): string
    {
        return app(ScheduleEngine::class)
            ->shiftToWorkingDay(Carbon::parse($date), $branchId)
            ->toDateString();
    }

    public function test_all_branches_holiday_applies_to_every_branch(): void
    {
        $this->manager();
        $b101 = $this->branch('101');
        $b102 = $this->branch('102');

        $this->post('/settings/holidays', [
            'scope' => 'all', 'details' => 'Scope All Holiday',
            'holiday_date' => self::HOLIDAY_SUNDAY,
        ])->assertSessionHas('success');

        $row = Holiday::where('details', 'Scope All Holiday')->firstOrFail();
        $this->assertNull($row->branch_id);
        $this->assertNull($row->samity_id);

        // Honoured through the real check path for BOTH branches.
        $this->assertSame(self::NEXT_WORKING, $this->shifted(self::HOLIDAY_SUNDAY, $b101->id));
        $this->assertSame(self::NEXT_WORKING, $this->shifted(self::HOLIDAY_SUNDAY, $b102->id));
    }

    public function test_all_branches_range_creates_one_global_row_per_day(): void
    {
        $this->manager();

        $this->post('/settings/holidays', [
            'scope' => 'all', 'details' => 'Scope All Range',
            'date_from' => '2027-01-03', 'date_to' => '2027-01-04',
        ])->assertSessionHas('success');

        $rows = Holiday::where('details', 'Scope All Range')->get();
        $this->assertCount(2, $rows);
        $this->assertTrue($rows->every(fn (Holiday $h) => $h->branch_id === null));
    }

    public function test_branch_wise_holiday_applies_only_to_that_branch(): void
    {
        $this->manager();
        $b101 = $this->branch('101');
        $b102 = $this->branch('102');

        $this->post('/settings/holidays', [
            'scope' => 'branch', 'branch_id' => $b102->id, 'details' => 'Scope Branch Holiday',
            'holiday_date' => self::HOLIDAY_SUNDAY,
        ])->assertSessionHas('success');

        $this->assertSame(self::NEXT_WORKING, $this->shifted(self::HOLIDAY_SUNDAY, $b102->id));
        $this->assertSame(self::HOLIDAY_SUNDAY, $this->shifted(self::HOLIDAY_SUNDAY, $b101->id));
    }

    public function test_legacy_post_without_scope_stays_branch_wise(): void
    {
        $this->manager();
        $b101 = $this->branch('101');
        $b102 = $this->branch('102');

        $this->post('/settings/holidays', [
            'branch_id' => $b101->id, 'details' => 'Legacy Holiday',
            'holiday_date' => self::HOLIDAY_SUNDAY,
        ])->assertSessionHas('success');

        $row = Holiday::where('details', 'Legacy Holiday')->firstOrFail();
        $this->assertSame($b101->id, $row->branch_id);
        $this->assertSame(self::NEXT_WORKING, $this->shifted(self::HOLIDAY_SUNDAY, $b101->id));
        $this->assertSame(self::HOLIDAY_SUNDAY, $this->shifted(self::HOLIDAY_SUNDAY, $b102->id));
    }

    public function test_edit_all_branches_holiday_moves_it_for_all_branches(): void
    {
        $this->manager();
        $b101 = $this->branch('101');
        $b102 = $this->branch('102');

        $this->post('/settings/holidays', [
            'scope' => 'all', 'details' => 'Editable Holiday',
            'holiday_date' => self::HOLIDAY_SUNDAY,
        ])->assertSessionHas('success');
        $row = Holiday::where('details', 'Editable Holiday')->firstOrFail();

        // Sunday 2026-12-20 is the new date.
        $this->put("/settings/holidays/{$row->id}", [
            'scope' => 'all', 'details' => 'Editable Holiday Moved',
            'holiday_date' => '2026-12-20',
        ])->assertSessionHas('success');

        $row->refresh();
        $this->assertSame('Editable Holiday Moved', $row->details);
        $this->assertSame('2026-12-20', $row->holiday_date->toDateString());
        $this->assertNull($row->branch_id);

        // Old date free again for both branches; new date honoured by both.
        $this->assertSame(self::HOLIDAY_SUNDAY, $this->shifted(self::HOLIDAY_SUNDAY, $b101->id));
        $this->assertSame(self::HOLIDAY_SUNDAY, $this->shifted(self::HOLIDAY_SUNDAY, $b102->id));
        $this->assertSame('2026-12-21', $this->shifted('2026-12-20', $b101->id));
        $this->assertSame('2026-12-21', $this->shifted('2026-12-20', $b102->id));

        // Narrowing the scope to branch-wise keeps it only for that branch.
        $this->put("/settings/holidays/{$row->id}", [
            'scope' => 'branch', 'branch_id' => $b102->id, 'details' => 'Editable Holiday Moved',
            'holiday_date' => '2026-12-20',
        ])->assertSessionHas('success');
        $this->assertSame('2026-12-20', $this->shifted('2026-12-20', $b101->id));
        $this->assertSame('2026-12-21', $this->shifted('2026-12-20', $b102->id));
    }

    public function test_delete_all_branches_holiday_removes_it_for_all(): void
    {
        $this->manager();
        $b101 = $this->branch('101');
        $b102 = $this->branch('102');

        $this->post('/settings/holidays', [
            'scope' => 'all', 'details' => 'Deletable Holiday',
            'holiday_date' => self::HOLIDAY_SUNDAY,
        ])->assertSessionHas('success');
        $row = Holiday::where('details', 'Deletable Holiday')->firstOrFail();
        $this->assertSame(self::NEXT_WORKING, $this->shifted(self::HOLIDAY_SUNDAY, $b101->id));

        $this->delete("/settings/holidays/{$row->id}")->assertSessionHas('success');

        $this->assertNull(Holiday::find($row->id));
        $this->assertSame(self::HOLIDAY_SUNDAY, $this->shifted(self::HOLIDAY_SUNDAY, $b101->id));
        $this->assertSame(self::HOLIDAY_SUNDAY, $this->shifted(self::HOLIDAY_SUNDAY, $b102->id));
    }

    public function test_all_branches_holiday_shows_in_list_with_all_scope(): void
    {
        $this->manager();

        $this->post('/settings/holidays', [
            'scope' => 'all', 'details' => 'Listed All Holiday',
            'holiday_date' => self::HOLIDAY_SUNDAY,
        ])->assertSessionHas('success');

        $response = $this->get('/settings/holidays')->assertOk();
        $holidays = collect($response->viewData('page')['props']['holidays']);
        $listed = $holidays->firstWhere('details', 'Listed All Holiday');
        $this->assertNotNull($listed, 'all-branches holiday must appear in the list');
        $this->assertSame('all', $listed['scope']);
        $this->assertNull($listed['branch']);
    }
}
