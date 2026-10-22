<?php

namespace Tests\Feature;

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Language switcher contract (owner report, 2026-10-21):
 * default is English, a switch applies to the very next render, and the
 * choice persists (users.locale + a 1-year cookie) across reloads.
 */
class LocaleSwitchTest extends TestCase
{
    private function user(): User
    {
        return User::factory()->create(['must_change_password' => false, 'locale' => 'en']);
    }

    public function test_default_locale_is_english(): void
    {
        $this->actingAs($this->user())->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('locale', 'en')
                ->where('translations.welcome', 'Welcome')
            );
    }

    public function test_switch_persists_to_database_and_applies_immediately(): void
    {
        $user = $this->user();

        $this->actingAs($user)->post('/locale/bn')
            ->assertRedirect()
            ->assertCookie('locale', 'bn');

        $this->assertSame('bn', $user->fresh()->locale);

        // The very next render already uses the new language (no lag).
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('locale', 'bn')
                ->where('translations.welcome', 'স্বাগতম')
            );

        // Switching back persists too.
        $this->post('/locale/en')->assertCookie('locale', 'en');
        $this->assertSame('en', $user->fresh()->locale);
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
    }

    public function test_saved_user_locale_is_served_on_a_fresh_session(): void
    {
        // No session locale, no cookie: the user record alone must carry
        // the choice across a full page refresh / new browser session.
        $user = User::factory()->create(['must_change_password' => false, 'locale' => 'bn']);

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('locale', 'bn')
                ->where('translations.welcome', 'স্বাগতম')
            );
    }

    public function test_guest_language_choice_survives_login(): void
    {
        // Pick Bangla on the login page (writes session + cookie only),
        // then log in as a user whose record still says English. The
        // guest choice is the latest explicit one: login must adopt it
        // instead of SetLocale's user-first priority stomping it with
        // the record's default (the "reverts to English" report).
        $user = User::factory()->create([
            'must_change_password' => false,
            'locale' => 'en',
            'password' => \Illuminate\Support\Facades\Hash::make('Secret@123'),
        ]);

        $this->post('/locale/bn')->assertRedirect();

        $this->post('/login', ['username' => $user->username, 'password' => 'Secret@123'])
            ->assertRedirect(route('home'));

        $this->assertSame('bn', $user->fresh()->locale);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('locale', 'bn')
                ->where('translations.welcome', 'স্বাগতম')
            );
    }

    public function test_guest_sees_english_by_default_and_cookie_is_honoured(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));

        // A remembered guest choice (cookie from an earlier switch) wins
        // over the default — this is what makes the login page persistent.
        $this->withCookie('locale', 'bn')->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('locale', 'bn'));
    }
}
