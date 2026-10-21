<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\LoginAudit;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\TotpService;
use App\Support\PermissionCatalog;
use Database\Seeders\AdminUserSeeder;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Plan Step 2 acceptance: seeded permissions/roles, username login with
 * lockout, TOTP forced for Super Admin/Branch Manager/Accountant,
 * permission-gated admin pages, SoD, branch restrictions.
 */
class RbacTest extends TestCase
{
    use DatabaseTransactions;

    /** Authenticate and mark the session TOTP-passed (mandatory roles). */
    private function actingAsTotpPassed(User $user): static
    {
        $this->actingAs($user);
        $this->withSession(['totp_passed_at' => now()->toDateTimeString()]);

        return $this;
    }

    private function makeUser(?string $role = null, array $attrs = []): User
    {
        $user = User::factory()->create(array_merge([
            'password' => Hash::make('secret-pass'),
            'must_change_password' => false,
        ], $attrs));
        if ($role) {
            $user->assignRole($role);
        }

        return $user;
    }

    public function test_permission_catalog_is_seeded_with_139_permissions_and_6_roles(): void
    {
        $this->assertSame(139, count(PermissionCatalog::all()));
        $this->assertSame(139, Permission::count());
        $this->assertSame(6, Role::count());
        $superAdmin = Role::where('name', 'super-admin')->first();
        $this->assertTrue((bool) $superAdmin->is_system);
        $this->assertSame(139, $superAdmin->permissions()->count());
        $manager = Role::where('name', 'branch-manager')->first();
        $this->assertFalse($manager->hasPermissionTo('settings.users-manage'));
        $this->assertFalse($manager->hasPermissionTo('accounts.breakglass'));
    }

    public function test_seeded_super_admin_can_log_in_and_pass_totp_enrollment(): void
    {
        $admin = User::where('username', AdminUserSeeder::USERNAME)->first();
        $this->assertNotNull($admin);
        $this->assertTrue($admin->hasRole('super-admin'));

        // First login: must change the seeded password first.
        $this->post('/login', ['username' => 'admin', 'password' => AdminUserSeeder::INITIAL_PASSWORD])
            ->assertRedirect('/');
        $this->get('/')->assertRedirect(route('password.change'));

        $this->post('/password/change', [
            'current_password' => AdminUserSeeder::INITIAL_PASSWORD,
            'password' => 'BrandNew#Pass1',
            'password_confirmation' => 'BrandNew#Pass1',
        ])->assertRedirect('/');

        // TOTP-mandatory role: app is gated until enrollment completes.
        $this->get('/')->assertRedirect(route('totp.setup'));
        $this->get('/totp/setup')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/TotpSetup')->has('secret')->has('qrSvg'));

        $secret = session('totp_setup_secret');
        $code = app(Google2FA::class)->getCurrentOtp($secret);
        $this->post('/totp/setup', ['code' => $code])->assertRedirect('/');
        $this->assertTrue($admin->fresh()->hasTotpEnabled());
        $this->assertNotNull(LoginAudit::where('user_id', $admin->id)->where('success', true)->first());
        $this->get('/')->assertOk();
    }

    public function test_login_lockout_after_five_failed_attempts(): void
    {
        $user = $this->makeUser();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['username' => $user->username, 'password' => 'wrong']);
        }
        $user->refresh();
        $this->assertSame(5, $user->failed_attempts);
        $this->assertTrue($user->isLocked());

        $this->assertSame(5, LoginAudit::where('user_id', $user->id)->where('success', false)->count());

        // Even the correct password is refused while locked.
        $this->post('/login', ['username' => $user->username, 'password' => 'secret-pass'])
            ->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $user = $this->makeUser(null, ['is_active' => false]);
        $this->post('/login', ['username' => $user->username, 'password' => 'secret-pass'])
            ->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_totp_challenge_required_on_subsequent_login_for_mandatory_role(): void
    {
        $totp = app(TotpService::class);
        $secret = $totp->generateSecret();
        $manager = $this->makeUser('branch-manager', [
            'totp_secret' => $secret, 'totp_confirmed_at' => now(),
        ]);

        $this->post('/login', ['username' => $manager->username, 'password' => 'secret-pass'])->assertRedirect('/');
        $this->get('/')->assertRedirect(route('totp.challenge'));

        $code = app(Google2FA::class)->getCurrentOtp($secret);
        $this->post('/totp/challenge', ['code' => $code])->assertRedirect('/');
        $this->get('/')->assertOk();
    }

    public function test_field_officer_needs_no_totp(): void
    {
        $officer = $this->makeUser('field-officer');
        $this->post('/login', ['username' => $officer->username, 'password' => 'secret-pass'])->assertRedirect('/');
        $this->get('/')->assertOk();
    }

    public function test_users_page_requires_users_manage_permission(): void
    {
        $this->actingAs($this->makeUser('field-officer'))->get('/settings/users')->assertForbidden();
        $this->actingAsTotpPassed($this->makeUser('super-admin'))->get('/settings/users')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Settings/Users/Index')->has('users')->has('roles')->has('branches'));
    }

    public function test_admin_can_create_user_with_roles_and_branch_assignment(): void
    {
        $admin = $this->makeUser('super-admin');
        $branch = Branch::where('code', '102')->first();
        // Real samity ids since Step 7 (samity_user.samity_id FK is live).
        $samityIds = \App\Models\Samity::withoutGlobalScopes()->orderBy('id')->pluck('id')->all();

        $this->actingAsTotpPassed($admin)->post('/settings/users', [
            'name' => 'Karim Uddin', 'username' => 'karim', 'email' => 'karim@example.com',
            'password' => 'temp-pass-123', 'locale' => 'bn',
            'roles' => ['field-officer'],
            'branch_ids' => [$branch->id], 'branch_children' => [],
            'samity_ids' => implode(', ', $samityIds),
        ])->assertRedirect()->assertSessionHas('success');

        $created = User::where('username', 'karim')->first();
        $this->assertTrue($created->hasRole('field-officer'));
        $this->assertTrue($created->must_change_password);
        $this->assertSame([$branch->id], $created->branches->pluck('id')->all());
        $this->assertSame($samityIds, \Illuminate\Support\Facades\DB::table('samity_user')->where('user_id', $created->id)->pluck('samity_id')->all());
    }

    public function test_cannot_remove_own_users_manage_access(): void
    {
        $admin = $this->makeUser('super-admin');
        $this->actingAsTotpPassed($admin)->put("/settings/users/{$admin->id}", [
            'name' => $admin->name, 'username' => $admin->username, 'roles' => ['field-officer'],
        ])->assertSessionHas('error');
        $this->assertTrue($admin->fresh()->hasRole('super-admin'));
    }

    public function test_last_super_admin_cannot_be_deactivated(): void
    {
        $only = $this->makeUser('super-admin');
        $other = $this->makeUser('super-admin');
        $seeded = User::where('username', AdminUserSeeder::USERNAME)->first();

        // Deactivate every other super admin so $only is the last active one.
        $this->actingAsTotpPassed($only)->post("/settings/users/{$other->id}/toggle-active")->assertSessionHas('success');
        $this->actingAsTotpPassed($only)->post("/settings/users/{$seeded->id}/toggle-active")->assertSessionHas('success');
        $this->assertFalse($other->fresh()->is_active);
        $this->assertFalse($seeded->fresh()->is_active);

        $actor = $this->makeUser('branch-manager');
        $actor->givePermissionTo('settings.users-manage');
        $this->actingAsTotpPassed($actor)->post("/settings/users/{$only->id}/toggle-active")->assertSessionHas('error');
        $this->assertTrue($only->fresh()->is_active);
    }

    public function test_role_matrix_update_and_system_role_protection(): void
    {
        $admin = $this->makeUser('super-admin');
        $role = Role::create(['name' => 'data-entry', 'label' => 'Data Entry']);

        $this->actingAsTotpPassed($admin)->put("/settings/roles/{$role->id}", [
            'name' => 'data-entry', 'label' => 'Data Entry', 'description' => 'x',
            'permissions' => ['members.view', 'members.create'],
        ])->assertSessionHas('success');
        $this->assertTrue($role->fresh()->hasPermissionTo('members.create'));

        // System roles: delete blocked.
        $system = Role::where('name', 'auditor')->first();
        $this->actingAsTotpPassed($admin)->delete("/settings/roles/{$system->id}")->assertSessionHas('error');
        $this->assertNotNull(Role::find($system->id));
    }

    public function test_permissions_catalog_page_groups_by_module(): void
    {
        $this->actingAsTotpPassed($this->makeUser('super-admin'))->get('/settings/permissions')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Settings/Permissions/Index')->has('permissionGroups', 16));
    }

    public function test_branch_switcher_lists_only_assigned_branches(): void
    {
        $branch = Branch::where('code', '102')->first();
        $officer = $this->makeUser('field-officer');
        $officer->branches()->attach($branch->id, ['include_children' => false]);

        $this->actingAs($officer)->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('branches', 1)
                ->where('branches.0.code', '102')
                ->where('activeBranch.code', '102'));

        // Switching to an unassigned branch is forbidden.
        $head = Branch::where('code', '101')->first();
        $this->actingAs($officer)->post('/branch/switch', ['branch_id' => $head->id])->assertForbidden();
    }

    public function test_include_children_assignment_rolls_down_the_tree(): void
    {
        $head = Branch::where('code', '101')->first();
        $officer = $this->makeUser('field-officer');
        $officer->branches()->attach($head->id, ['include_children' => true]);

        $this->actingAs($officer)->get('/')
            ->assertInertia(fn (Assert $page) => $page->has('branches', 2));
    }

    public function test_segregation_of_duties_rejects_same_maker_checker(): void
    {
        $service = app(AuthorizationService::class);
        $service->assertDifferentMakerChecker(1, 2); // must not throw
        $this->expectException(DomainException::class);
        $service->assertDifferentMakerChecker(7, 7);
    }

    public function test_passwords_use_argon2id(): void
    {
        $this->assertSame('argon2id', config('hashing.driver'));
        $hash = Hash::make('sample-password');
        $this->assertSame('argon2id', password_get_info($hash)['algoName']);
    }
}
