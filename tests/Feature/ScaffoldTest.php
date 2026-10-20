<?php

namespace Tests\Feature;

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ScaffoldTest extends TestCase
{
    /**
     * Since Step 2 the app requires login; tests authenticate as a plain
     * user with no mandatory-TOTP role (Step 0's shell checks stay valid).
     */
    private function user(): User
    {
        return User::factory()->create(['must_change_password' => false]);
    }

    public function test_home_page_renders_welcome_with_shared_translations(): void
    {
        $this->actingAs($this->user())->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Welcome')
                ->has('locale')
                ->has('translations_version')
                ->where('translations.welcome', 'স্বাগতম') // default locale = bn
            );
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_locale_switch_to_english_swaps_translations(): void
    {
        $this->actingAs($this->user())->post('/locale/en')->assertRedirect();

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Welcome')
                ->where('locale', 'en')
                ->where('translations.welcome', 'Welcome')
            );
    }

    public function test_locale_switch_rejects_unknown_locale(): void
    {
        $this->post('/locale/fr')->assertNotFound();
    }

    public function test_database_is_mysql_never_sqlite(): void
    {
        $this->assertSame('mysql', config('database.default'));
        $version = \Illuminate\Support\Facades\DB::selectOne('SELECT VERSION() AS v')->v;
        $this->assertStringStartsWith('8.4', $version);
    }
}
