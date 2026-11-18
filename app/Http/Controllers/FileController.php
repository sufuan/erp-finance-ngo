<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves private-disk files. There is NO public URL for an upload:
 * the requester must be authenticated (or hold a valid signed URL —
 * that variant is registered separately in routes/web.php).
 */
class FileController extends Controller
{
    /**
     * The organization logo is public branding (it heads every printed
     * report), so — unlike member documents — it is served without auth:
     * the login page must be able to show it. Only the single current
     * logo of the active organization profile is ever exposed here.
     */
    public function orgLogo(): StreamedResponse
    {
        $profile = \App\Models\OrganizationProfile::where('is_active', true)->first()
            ?? \App\Models\OrganizationProfile::first();
        $path = $profile?->logoAbsolutePath();
        if (! $path) {
            abort(404);
        }

        return Storage::disk('private')->download($profile->logo_path);
    }

    public function show(Request $request, string $path): StreamedResponse
    {
        if (! $request->user()) {
            abort(403);
        }

        $path = ltrim($path, '/');
        if (str_contains($path, '..')) {
            abort(404);
        }

        $disk = Storage::disk('private');
        if (! $disk->exists($path)) {
            abort(404);
        }

        return $disk->download($path);
    }
}
