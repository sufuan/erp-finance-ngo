<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class OrganizationProfile extends Model
{
    protected $fillable = [
        'name_en', 'name_bn', 'org_type', 'registration_no', 'logo_path',
        'mobile_app_name', 'contact_person', 'address', 'phone', 'email', 'is_active',
    ];

    /**
     * Absolute filesystem path of the uploaded logo (private disk), or null
     * when no logo is uploaded / the file is gone. PDF engines read the
     * logo from disk, never over HTTP.
     */
    public function logoAbsolutePath(): ?string
    {
        if (! $this->logo_path) {
            return null;
        }
        $disk = Storage::disk('private');

        return $disk->exists($this->logo_path) ? $disk->path($this->logo_path) : null;
    }

    /**
     * The logo as a base64 data URI — the one reference form that renders
     * identically in both PDF engines (mPDF and headless Chromium).
     */
    public function logoDataUri(): ?string
    {
        $path = $this->logoAbsolutePath();
        if (! $path) {
            return null;
        }
        $mime = mime_content_type($path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
    }

    /**
     * Public URL of the logo for the web UI (app shell + login page). The
     * logo is public branding, so it is served by the dedicated /org-logo
     * route instead of the auth-gated /files route; the mtime query keeps
     * browsers from caching a replaced logo.
     */
    public function logoUrl(): ?string
    {
        $path = $this->logoAbsolutePath();

        return $path ? '/org-logo?v='.filemtime($path) : null;
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public static function active(): ?self
    {
        return static::where('is_active', true)->first();
    }
}
