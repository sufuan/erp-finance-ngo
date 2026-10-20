<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LocaleController extends Controller
{
    /**
     * Switch the UI locale. Persists to users.locale when the user is
     * authenticated and the column exists (Step 2), always to session.
     */
    public function switch(Request $request, string $locale): RedirectResponse
    {
        if (! in_array($locale, ['en', 'bn'], true)) {
            abort(404);
        }

        $request->session()->put('locale', $locale);

        $user = $request->user();
        if ($user && \Illuminate\Support\Facades\Schema::hasColumn('users', 'locale')) {
            $user->forceFill(['locale' => $locale])->save();
        }

        app()->setLocale($locale);

        return back();
    }
}
