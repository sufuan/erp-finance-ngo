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

        $branchData = ['branches' => [], 'activeBranch' => null, 'businessDate' => null];
        if (\Illuminate\Support\Facades\Schema::hasTable('branches')) {
            $active = $request->attributes->get('active_branch');
            $branchData = [
                'branches' => \App\Models\Branch::active()
                    ->orderBy('code')
                    ->get(['id', 'code', 'name', 'type'])
                    ->toArray(),
                'activeBranch' => $active ? [
                    'id' => $active->id, 'code' => $active->code,
                    'name' => $active->name, 'type' => $active->type,
                ] : null,
                'businessDate' => $request->attributes->get('business_date'),
            ];
        }

        return [
            ...parent::share($request),
            'appName' => $this->appDisplayName(),
            'orgName' => $this->orgName($locale),
            'locale' => $locale,
            'translations' => $translations,
            'translations_version' => file_exists($langPath) ? md5_file($langPath) : null,
            ...$branchData,
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

    /**
     * App name is settings-driven from Step 3 (organization_profiles /
     * settings); before that table exists, fall back to config.
     */
    private function appDisplayName(): string
    {
        if (\Illuminate\Support\Facades\Schema::hasTable('settings')) {
            try {
                $value = app(\App\Services\SettingsService::class)->get('app.name');
                if ($value) {
                    return (string) $value;
                }
            } catch (\Throwable) {
                // Settings not seeded yet.
            }
        }

        return (string) config('app.name');
    }

    private function orgName(string $locale): ?string
    {
        if (\Illuminate\Support\Facades\Schema::hasTable('organization_profiles')) {
            try {
                $profile = \App\Models\OrganizationProfile::where('is_active', true)->first();
                if ($profile) {
                    return $locale === 'bn' ? $profile->name_bn : $profile->name_en;
                }
            } catch (\Throwable) {
                // Profile not seeded yet.
            }
        }

        return null;
    }
}
