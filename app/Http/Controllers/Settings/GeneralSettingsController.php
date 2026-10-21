<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\OrganizationProfile;
use App\Models\Setting;
use App\Services\AuditService;
use App\Services\SettingsService;
use App\Services\UploadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → General Settings (Step 3): tabbed UI over organization_profiles
 * + settings. Every change is audited and takes effect immediately
 * (SettingsService busts its Redis cache on write).
 */
class GeneralSettingsController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditService $audit,
        private readonly UploadService $uploads,
    ) {}

    public function index(): Response
    {
        $profile = OrganizationProfile::active();

        return Inertia::render('Settings/General/Index', [
            'profile' => $profile ? [
                'name_en' => $profile->name_en, 'name_bn' => $profile->name_bn,
                'org_type' => $profile->org_type, 'registration_no' => $profile->registration_no,
                'mobile_app_name' => $profile->mobile_app_name, 'contact_person' => $profile->contact_person,
                'address' => $profile->address, 'phone' => $profile->phone, 'email' => $profile->email,
                'logo_path' => $profile->logo_path,
            ] : null,
            'settings' => $this->settings->all(),
            'settingMeta' => Setting::orderBy('key')->get(['key', 'group', 'type', 'description'])
                ->mapWithKeys(fn (Setting $s) => [$s->key => ['group' => $s->group, 'type' => $s->type, 'description' => $s->description]]),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'profile.name_en' => ['required', 'string', 'max:255'],
            'profile.name_bn' => ['required', 'string', 'max:255'],
            'profile.org_type' => ['required', 'string', 'max:255'],
            'profile.registration_no' => ['required', 'string', 'max:255'],
            'profile.mobile_app_name' => ['required', 'string', 'max:255'],
            'profile.contact_person' => ['nullable', 'string', 'max:255'],
            'profile.address' => ['nullable', 'string', 'max:255'],
            'profile.phone' => ['nullable', 'string', 'max:50'],
            'profile.email' => ['nullable', 'email', 'max:255'],
            'logo' => ['nullable', 'file', 'max:2048'],
            'settings' => ['required', 'array'],
        ]);

        $userId = $request->user()->id;

        // Organization profile (+ optional logo upload).
        $profile = OrganizationProfile::active() ?? new OrganizationProfile(['is_active' => true]);
        $oldProfile = $profile->exists ? $profile->only(['name_en', 'name_bn', 'org_type', 'registration_no', 'mobile_app_name', 'contact_person', 'address', 'phone', 'email', 'logo_path']) : null;
        $profile->fill($validated['profile']);
        if ($request->hasFile('logo')) {
            $previousLogo = $profile->logo_path;
            // UploadService::store() returns the private-disk path string.
            $profile->logo_path = $this->uploads->store($request->file('logo'), 'logo', 'logos');
            if ($previousLogo && $previousLogo !== $profile->logo_path) {
                Storage::disk('private')->delete($previousLogo);
            }
        }
        $profile->save();
        $this->settings->bustOrganization();
        if ($oldProfile !== $profile->only(array_keys($oldProfile ?? []))) {
            $this->audit->log($userId, 'organization.updated', 'organization_profiles', $profile->id, $oldProfile, $profile->only(['name_en', 'name_bn', 'org_type', 'registration_no', 'mobile_app_name', 'contact_person', 'address', 'phone', 'email', 'logo_path']));
        }

        // Settings: only known keys, re-typed per their stored type.
        foreach ($request->input('settings', []) as $key => $raw) {
            $setting = Setting::where('key', $key)->first();
            if (! $setting) {
                continue;
            }
            $value = match ($setting->type) {
                'number' => is_numeric($raw) ? (str_contains((string) $raw, '.') ? (float) $raw : (int) $raw) : $raw,
                'boolean' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
                default => $raw,
            };
            $old = $setting->decodedValue();
            if ($old !== $value) {
                $this->settings->set($key, $value, $userId);
                $this->audit->log($userId, 'settings.updated', 'settings', $setting->id, [$key => $old], [$key => $value]);
            }
        }

        return back()->with('success', __('saved'));
    }
}
