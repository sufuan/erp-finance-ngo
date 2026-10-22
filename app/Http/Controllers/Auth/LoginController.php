<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\LoginAudit;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    /**
     * Username + password login with lockout (5 fails -> 15 minutes),
     * login auditing, and last-login tracking (plan Step 2).
     */
    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('username', $credentials['username'])->first();

        $fail = function (string $key) use ($request, $user) {
            LoginAudit::create([
                'user_id' => $user?->id,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'success' => false,
            ]);
            throw ValidationException::withMessages(['username' => __($key)]);
        };

        if ($user && $user->isLocked()) {
            $fail('account_locked');
        }

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            if ($user) {
                $attempts = $user->failed_attempts + 1;
                $user->forceFill([
                    'failed_attempts' => $attempts,
                    'locked_until' => $attempts >= 5 ? now()->addMinutes(15) : $user->locked_until,
                ])->save();
            }
            $fail('invalid_credentials');
        }

        if (! $user->is_active) {
            $fail('account_inactive');
        }

        $user->forceFill([
            'failed_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        LoginAudit::create([
            'user_id' => $user->id,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'success' => true,
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        // A language picked as a guest lives in the session (the login-page
        // switcher writes it there). It is the person's latest explicit
        // choice, so adopt it as the user's saved locale — otherwise
        // SetLocale's user-first priority silently overrides it with the
        // record's previous/default value and the app "reverts" language
        // right after login.
        $sessionLocale = $request->session()->get('locale');
        if (in_array($sessionLocale, ['en', 'bn'], true)
            && \Illuminate\Support\Facades\Schema::hasColumn('users', 'locale')
            && $user->locale !== $sessionLocale) {
            $user->forceFill(['locale' => $sessionLocale])->save();
        }

        return redirect()->intended(route('home'));
    }
}
