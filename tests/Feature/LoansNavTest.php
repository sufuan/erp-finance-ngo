<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/** Sidebar nav: the Loans menu button is driven by the shared auth.can.loans flag. */
class LoansNavTest extends TestCase
{
    use DatabaseTransactions;

    private function logInAs(User $user): void
    {
        $this->actingAs($user)->withSession(['totp_passed_at' => now()->toDateTimeString()]);
    }

    public function test_super_admin_sees_loans_nav_flag(): void
    {
        $admin = User::factory()->create(['must_change_password' => false]);
        $admin->assignRole('super-admin');
        $this->logInAs($admin);

        $response = $this->get('/loans/proposals');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('auth.can.loans', true)
        );
    }

    public function test_user_without_loans_view_has_no_loans_nav_flag_and_is_blocked(): void
    {
        $user = User::factory()->create(['must_change_password' => false]);
        $this->logInAs($user);

        $this->assertFalse($user->can('loans.view'));

        $response = $this->get('/loans/proposals');

        $response->assertForbidden();
    }
}
