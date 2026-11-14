<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Samity;
use App\Models\User;
use App\Services\SamityAccessService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Owner's rule, end to end (2026-11-14, stated twice and strictly):
 * a user sees EXACTLY the samities ticked for them on the user form
 * (`samity_user`) — 1 tick → that samity only, 2 ticks → those 2, all
 * ticked → all, and NO ticks → NOT ONE samity. This applies to every
 * role (custom roles, Field Officer, Branch Manager alike); visibility
 * comes only from the assignment, never from a role name. The single
 * exemption is Super Admin (the IT-department account), which is
 * unrestricted so the system stays administrable.
 *
 * Seeded stand-ins: 'Talgachi Samity' plays the owner's "BU" samity and
 * 'Talgachi Mohila Samity' is the other samity of the same branch (102).
 */
class SamityVisibilityTest extends TestCase
{
    use DatabaseTransactions;

    private Branch $branch;

    private Samity $bu;

    private Samity $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::where('code', '102')->firstOrFail();
        $this->bu = Samity::withoutGlobalScopes()->where('name', 'Talgachi Samity')->firstOrFail();
        $this->other = Samity::withoutGlobalScopes()->where('name', 'Talgachi Mohila Samity')->firstOrFail();
    }

    private function admin(): User
    {
        $user = User::factory()->create(['must_change_password' => false]);
        $user->assignRole('super-admin');
        $this->actingAs($user);
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);

        return $user;
    }

    /** Create the custom collector role through the real endpoint. */
    private function makeCollectorRole(): void
    {
        $this->admin();
        $this->post('/settings/roles', [
            'name' => 'collector', 'label' => 'Collector', 'description' => 'Field collector',
            'permissions' => [
                'members.view', 'members.create', 'members.edit',
                'collection.view', 'collection.collect', 'collection.sheets',
                'collection.reports', 'collection.print',
                'loans.view', 'loans.print', 'loans.view-schedule',
                'deposits.view', 'deposits.receive', 'deposits.view-statement', 'deposits.print',
                'files.view', 'files.upload',
            ],
        ])->assertRedirect()->assertSessionHas('success');
    }

    /** Create a user through the real user-store endpoint, as the picker posts it. */
    private function createUserViaForm(string $username, array $roles, array $samityIds): User
    {
        $this->admin();
        $this->post('/settings/users', [
            'name' => ucfirst($username), 'username' => $username,
            'email' => $username.'@tpda.example', 'password' => 'Password@123',
            'locale' => 'en', 'roles' => $roles,
            'branch_id' => $this->branch->id, 'samity_ids' => $samityIds,
        ])->assertRedirect()->assertSessionHas('success');

        $user = User::where('username', $username)->firstOrFail();
        // The forced first-login password change is orthogonal plumbing;
        // clear it so the account can be acted as directly.
        $user->forceFill(['must_change_password' => false])->save();

        return $user;
    }

    /** @return int[] samity ids listed on the collection sheet for this user */
    private function sheetSamityIds(User $user): array
    {
        $response = $this->actingAs($user)
            ->withSession([
                'active_branch_id' => $this->branch->id,
                'totp_passed_at' => now()->toDateTimeString(),
            ])
            ->get('/collection/sheet');
        $response->assertOk();

        return collect($response->viewData('page')['props']['samities'])
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function test_custom_collector_with_one_tick_sees_only_his_samity(): void
    {
        // The role itself is custom — created through the real endpoint with
        // the field-worker permission set (collection + member work).
        $this->makeCollectorRole();

        $collector = $this->createUserViaForm('bu.collector', ['collector'], [$this->bu->id]);
        $this->assertDatabaseHas('samity_user', ['user_id' => $collector->id, 'samity_id' => $this->bu->id]);
        $this->assertTrue(app(SamityAccessService::class)->isRestricted($collector));

        $ids = $this->sheetSamityIds($collector);
        $this->assertContains($this->bu->id, $ids, 'Assigned samity must appear on the collection sheet');
        $this->assertNotContains($this->other->id, $ids, 'Unassigned samity of the same branch must not appear');
    }

    public function test_custom_collector_with_both_ticks_sees_both_samities(): void
    {
        $this->makeCollectorRole();

        $collector = $this->createUserViaForm('both.collector', ['collector'], [$this->bu->id, $this->other->id]);

        $ids = $this->sheetSamityIds($collector);
        $this->assertContains($this->bu->id, $ids);
        $this->assertContains($this->other->id, $ids);
    }

    public function test_field_officer_with_one_tick_sees_only_his_samity(): void
    {
        $officer = $this->createUserViaForm('bu.officer', ['field-officer'], [$this->bu->id]);
        $this->assertTrue(app(SamityAccessService::class)->isRestricted($officer));

        $ids = $this->sheetSamityIds($officer);
        $this->assertContains($this->bu->id, $ids);
        $this->assertNotContains($this->other->id, $ids);
    }

    public function test_field_officer_without_ticks_sees_not_one_samity(): void
    {
        // Strict owner rule: NO assignment → NOT ONE samity, even for the
        // Field Officer role. (This reverses the #76/#77 default, where
        // an unticked user saw the whole branch.)
        $officer = $this->createUserViaForm('free.officer', ['field-officer'], []);
        $this->assertTrue(app(SamityAccessService::class)->isRestricted($officer));
        $this->assertSame([], app(SamityAccessService::class)->assignedSamityIds($officer));

        $this->assertSame([], $this->sheetSamityIds($officer));
    }

    public function test_branch_manager_without_ticks_sees_not_one_samity(): void
    {
        // Strict owner rule: the Branch Manager role name grants no
        // samity visibility either — without ticks the sheet is EMPTY.
        // (Renamed from the #76/#77-era 'sees all branch samities' test:
        // the owner reversed that default on 2026-11-14 18:34.)
        $manager = $this->createUserViaForm('plain.manager', ['branch-manager'], []);
        $this->assertTrue(app(SamityAccessService::class)->isRestricted($manager));

        $this->assertSame([], $this->sheetSamityIds($manager));
    }

    public function test_branch_manager_with_one_tick_sees_only_that_samity(): void
    {
        $manager = $this->createUserViaForm('ticked.manager', ['branch-manager'], [$this->bu->id]);
        $this->assertTrue(app(SamityAccessService::class)->isRestricted($manager));

        $ids = $this->sheetSamityIds($manager);
        $this->assertContains($this->bu->id, $ids);
        $this->assertNotContains($this->other->id, $ids);
    }

    public function test_super_admin_is_unrestricted_and_sees_all_branch_samities(): void
    {
        // The one exemption: Super Admin (IT department) administers the
        // system, so it is never samity-restricted and needs no ticks.
        $admin = $this->admin();
        $this->assertFalse(app(SamityAccessService::class)->isRestricted($admin));

        $ids = $this->sheetSamityIds($admin);
        $this->assertContains($this->bu->id, $ids);
        $this->assertContains($this->other->id, $ids);
    }
}
