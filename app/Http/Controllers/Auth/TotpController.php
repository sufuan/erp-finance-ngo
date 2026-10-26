<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\TotpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * TOTP enrollment (first login for mandatory roles) and per-login
 * challenge. The secret is only persisted after a successful code —
 * enrollment in progress lives in the session. Every action redirects
 * home while the TOTP master switch (security.totp_enabled) is off.
 */
class TotpController extends Controller
{
    public function __construct(private readonly TotpService $totp) {}

    public function setup(Request $request): Response|RedirectResponse
    {
        if (! $this->totp->enabled()) {
            return redirect()->route('home');
        }

        $user = $request->user();
        if ($user->hasTotpEnabled()) {
            return redirect()->route('totp.challenge');
        }

        $secret = $request->session()->get('totp_setup_secret');
        if (! $secret) {
            $secret = $this->totp->generateSecret();
            $request->session()->put('totp_setup_secret', $secret);
        }

        // SettingsService arrives in Step 3; resolve lazily until then.
        $orgName = rescue(fn () => app(\App\Services\SettingsService::class)->get('organization.name', config('app.name')), config('app.name'), false);

        return Inertia::render('Auth/TotpSetup', [
            'secret' => $secret,
            'qrSvg' => $this->totp->qrSvg($user, $secret, (string) $orgName),
        ]);
    }

    public function confirmSetup(Request $request): RedirectResponse
    {
        if (! $this->totp->enabled()) {
            return redirect()->route('home');
        }

        $request->validate(['code' => ['required', 'string']]);
        $secret = $request->session()->get('totp_setup_secret');
        $user = $request->user();

        if (! $secret || ! $this->totp->verify($secret, $request->input('code'))) {
            throw ValidationException::withMessages(['code' => __('invalid_totp_code')]);
        }

        $user->forceFill(['totp_secret' => $secret, 'totp_confirmed_at' => now()])->save();
        $request->session()->forget('totp_setup_secret');
        $request->session()->put('totp_passed_at', now()->toDateTimeString());

        return redirect()->intended(route('home'))->with('success', __('two_factor_enabled'));
    }

    public function challenge(Request $request): Response|RedirectResponse
    {
        if (! $this->totp->enabled()) {
            return redirect()->route('home');
        }
        if (! $request->user()->hasTotpEnabled()) {
            return redirect()->route('totp.setup');
        }
        if ($request->session()->has('totp_passed_at')) {
            return redirect()->route('home');
        }

        return Inertia::render('Auth/TotpChallenge');
    }

    public function verifyChallenge(Request $request): RedirectResponse
    {
        if (! $this->totp->enabled()) {
            return redirect()->route('home');
        }

        $request->validate(['code' => ['required', 'string']]);
        $user = $request->user();

        if (! $user->hasTotpEnabled() || ! $this->totp->verify($user->totp_secret, $request->input('code'))) {
            throw ValidationException::withMessages(['code' => __('invalid_totp_code')]);
        }

        $request->session()->put('totp_passed_at', now()->toDateTimeString());

        return redirect()->intended(route('home'));
    }
}
