<?php

namespace Tests\Feature\Crud;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Platform shell: the real login round-trip (bad credentials rejected,
 * good credentials land in the app), the home hub, and the five module
 * hub pages. RouteSweepTest already covers every GET as an admin — this
 * suite covers the WRITE-side entry (login POST) and the landing pages
 * a user actually arrives on.
 */
class PlatformCrudTest extends CrudTestCase
{
    public function test_login_rejects_bad_credentials_and_accepts_good_ones(): void
    {
        $user = User::factory()->create([
            'username' => 'crud.login', 'password' => Hash::make('Secret@123'),
            'must_change_password' => false, 'is_active' => true,
        ]);
        $user->assignRole('super-admin');

        // The suite logs in as the sweep admin by default; the login
        // page itself is guest-only (an authed visit 302s home by design).
        auth()->logout();
        $this->get('/login')->assertOk();

        $this->post('/login', ['username' => 'crud.login', 'password' => 'wrong-password'])
            ->assertSessionHasErrors();
        $this->assertGuest();

        $this->post('/login', ['username' => 'crud.login', 'password' => 'Secret@123'])
            ->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_home_and_module_hubs_render_after_login(): void
    {
        $this->get('/')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Home'));

        foreach (['member', 'deposit', 'loan', 'hrm', 'settings'] as $module) {
            $this->get("/hub/{$module}")->assertOk()
                ->assertInertia(fn ($p) => $p->component('Hub/Show'));
        }
    }

    public function test_password_change_round_trip(): void
    {
        $user = User::factory()->create([
            'username' => 'crud.pwchange', 'password' => Hash::make('Secret@123'),
            'must_change_password' => false, 'is_active' => true,
        ]);
        $user->assignRole('super-admin');
        $this->actingAs($user);

        $this->get('/password/change')->assertOk();
        $this->post('/password/change', [
            'current_password' => 'Secret@123',
            'password' => 'NewSecret@456', 'password_confirmation' => 'NewSecret@456',
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertTrue(Hash::check('NewSecret@456', $user->fresh()->password));

        // Wrong current password is rejected with errors, not a 500.
        $this->post('/password/change', [
            'current_password' => 'not-the-password',
            'password' => 'Another@789', 'password_confirmation' => 'Another@789',
        ])->assertSessionHasErrors();
        $this->assertTrue(Hash::check('NewSecret@456', $user->fresh()->password));
    }
}
