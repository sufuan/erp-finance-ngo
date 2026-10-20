<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Hardened uploads (plan Step 1 / §4.6):
 * - files are stored on the private disk (outside the webroot) and served
 *   ONLY through FileController (auth) or a signed URL — never public links;
 * - images are re-encoded through GD on upload, stripping embedded payloads;
 * - spreadsheets are .xlsx only — legacy .xls is rejected outright;
 * - per-type size limits; extensions/MIME are verified, never trusted.
 * (PhpSpreadsheet parsing, with XXE disabled + zip-bomb guards, lands with
 * the import steps — 9A/20A.)
 */
class UploadService
{
    public const TYPES = [
        'photo' => ['mimes' => ['image/jpeg', 'image/png'], 'ext' => ['jpg', 'jpeg', 'png'], 'max_kb' => 2048],
        'document' => ['mimes' => ['application/pdf', 'image/jpeg', 'image/png'], 'ext' => ['pdf', 'jpg', 'jpeg', 'png'], 'max_kb' => 5120],
        'spreadsheet' => ['mimes' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip', // xlsx is a zip container; some browsers report this
        ], 'ext' => ['xlsx'], 'max_kb' => 10240],
    ];

    /**
     * Validate + store an upload. Returns the private-disk path.
     */
    public function store(UploadedFile $file, string $type, string $folder = 'uploads'): string
    {
        $rules = self::TYPES[$type] ?? throw new InvalidArgumentException("Unknown upload type: {$type}");

        $ext = strtolower($file->getClientOriginalExtension());
        if ($ext === 'xls') {
            throw new InvalidArgumentException('Legacy .xls files are not accepted — save as .xlsx.');
        }
        if (! in_array($ext, $rules['ext'], true)) {
            throw new InvalidArgumentException("File extension .{$ext} is not allowed for {$type} uploads.");
        }
        if ($file->getSize() > $rules['max_kb'] * 1024) {
            throw new InvalidArgumentException("File exceeds the {$rules['max_kb']} KB limit for {$type} uploads.");
        }

        // Verify real content type from the bytes, not the client's claim.
        $mime = $file->getMimeType();
        if ($type === 'photo') {
            return $this->storeReencodedImage($file, $folder);
        }
        if (! in_array($mime, $rules['mimes'], true)) {
            throw new InvalidArgumentException("File content type {$mime} is not allowed for {$type} uploads.");
        }

        $name = Str::uuid()->toString().'.'.$ext;

        return $file->storeAs($folder, $name, 'private');
    }

    /**
     * Decode and re-encode an image with GD so any payload smuggled in
     * metadata/appended data is destroyed. Output keeps the same format.
     */
    private function storeReencodedImage(UploadedFile $file, string $folder): string
    {
        $mime = $file->getMimeType();
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($file->getRealPath()),
            'image/png' => @imagecreatefrompng($file->getRealPath()),
            default => null,
        };
        if ($image === null || $image === false) {
            throw new InvalidArgumentException('The uploaded file is not a valid image.');
        }

        $ext = $mime === 'image/png' ? 'png' : 'jpg';
        $relative = $folder.'/'.Str::uuid()->toString().'.'.$ext;
        $absolute = Storage::disk('private')->path($relative);
        if (! is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0775, true);
        }

        $ok = $ext === 'png'
            ? imagepng($image, $absolute)
            : imagejpeg($image, $absolute, 88);
        imagedestroy($image);

        if (! $ok) {
            throw new InvalidArgumentException('The image could not be processed.');
        }

        return $relative;
    }
}
