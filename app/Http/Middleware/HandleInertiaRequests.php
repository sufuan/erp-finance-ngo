<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * Translations are decoded server-side from lang/{locale}.json (the
     * browser cannot require() them) and shared with a version hash so the
     * React client can cache them in localStorage keyed by that version.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $locale = app()->getLocale();
        $langPath = lang_path($locale.'.json');
        $translations = file_exists($langPath)
            ? (json_decode(file_get_contents($langPath), true) ?: [])
            : [];

        return [
            ...parent::share($request),
            'appName' => config('app.name'),
            'locale' => $locale,
            'translations' => $translations,
            'translations_version' => file_exists($langPath) ? md5_file($langPath) : null,
            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'username' => $request->user()->username ?? null,
                ] : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
