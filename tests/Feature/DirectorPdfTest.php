<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Client requirements 2026-11-18, Feature 5 — the Director can print
 * / save as PDF exactly what the Director page shows. The endpoint
 * is gated by the same director.view permission as the page.
 */
class DirectorPdfTest extends TestCase
{
    use DatabaseTransactions;

    public function test_director_can_download_the_dashboard_pdf(): void
    {
        $director = User::factory()->create(['must_change_password' => false]);
        $director->assignRole('director');

        $response = $this->actingAs($director)
            ->withSession(['totp_passed_at' => now()->toDateTimeString()])
            ->get('/director/pdf');

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertGreaterThan(3000, strlen($response->getContent()));
    }

    public function test_user_without_director_permission_gets_403(): void
    {
        $user = User::factory()->create(['must_change_password' => false]);
        $user->assignRole('branch-manager');

        $this->actingAs($user)
            ->withSession(['totp_passed_at' => now()->toDateTimeString()])
            ->get('/director/pdf')
            ->assertForbidden();
    }
}
