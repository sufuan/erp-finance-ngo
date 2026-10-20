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
