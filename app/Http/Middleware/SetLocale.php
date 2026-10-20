<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active UI locale for every web request.
 *
 * Priority: authenticated user's saved locale (users.locale, Step 2) →
 * session → app default (settings app.locale_default, seeded as "bn").
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = null;

        $user = $request->user();
        if ($user && isset($user->locale) && $user->locale) {
            $locale = $user->locale;
        }

        $locale ??= $request->session()->get('locale');

        $locale ??= config('app.locale', 'bn');

        if (! in_array($locale, ['en', 'bn'], true)) {
            $locale = 'bn';
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
