<?php

namespace App\Services;

use App\Models\OrganizationProfile;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Dynamic configuration (plan Step 3 / architecture §15). Every business
 * value — app name, rates, thresholds — is read through here and cached
 * in Redis; writes bust the cache so changes apply immediately.
 */
class SettingsService
{
    private const CACHE_PREFIX = 'settings.';

    public function get(string $key, mixed $default = null): mixed
    {
        $cached = Cache::rememberForever(self::CACHE_PREFIX.$key, function () use ($key) {
            $setting = Setting::where('key', $key)->first();

            return $setting ? ['found' => true, 'value' => $setting->decodedValue()] : ['found' => false, 'value' => null];
        });

        return $cached['found'] ? $cached['value'] : $default;
    }

    public function set(string $key, mixed $value, ?int $userId = null): void
    {
        $setting = Setting::where('key', $key)->first();
        $type = $setting?->type ?? (is_bool($value) ? 'boolean' : (is_array($value) ? 'json' : (is_numeric($value) ? 'number' : 'string')));

        Setting::updateOrCreate(
            ['key' => $key],
            ['value' => Setting::encodeValue($value, $type), 'type' => $type, 'group' => $setting?->group ?? strstr($key, '.', true) ?: 'general', 'updated_by' => $userId]
        );

        Cache::forget(self::CACHE_PREFIX.$key);
    }

    /** @return array<string, mixed> key => decoded value, for the settings UI. */
    public function all(): array
    {
        return Setting::orderBy('key')->get()
            ->mapWithKeys(fn (Setting $s) => [$s->key => $s->decodedValue()])
            ->all();
    }

    public function organization(): ?OrganizationProfile
    {
        return Cache::rememberForever(self::CACHE_PREFIX.'organization_profile', fn () => OrganizationProfile::active());
    }

    public function bustOrganization(): void
    {
        Cache::forget(self::CACHE_PREFIX.'organization_profile');
    }
}
