<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Step 2: temporary passwords must be changed before anything else. */
class EnsurePasswordChanged
{
    private const EXEMPT = ['password.change', 'password.change.update', 'logout', 'locale.switch'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $user->must_change_password && ! $request->routeIs(...self::EXEMPT)) {
            return redirect()->route('password.change');
        }

        return $next($request);
    }
}
