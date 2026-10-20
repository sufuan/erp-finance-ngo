<?php

namespace App\Http\Middleware;

use App\Services\TotpService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step 2: users in TOTP-mandatory roles must enroll (first login) and
 * pass a TOTP challenge (every login) before reaching the app.
 */
class EnsureTotpVerified
{
    private const EXEMPT = ['totp.setup', 'totp.setup.confirm', 'totp.challenge', 'totp.challenge.verify', 'logout', 'password.change', 'password.change.update', 'locale.switch'];

    public function __construct(private readonly TotpService $totp) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $this->totp->requiredFor($user)) {
            return $next($request);
        }
        if ($request->routeIs(...self::EXEMPT) || $request->session()->has('totp_passed_at')) {
            return $next($request);
        }

        return redirect()->route($user->hasTotpEnabled() ? 'totp.challenge' : 'totp.setup');
    }
}
