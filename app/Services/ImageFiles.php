<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ImageFiles
{
    // Bounds apply before decoding; decoding and a new JPEG canvas remove metadata.
    public function normalize(UploadedFile $file): array
    {
        abort_unless(extension_loaded('gd'), 503, 'Image processing is unavailable.');
        $fail = fn () => throw ValidationException::withMessages(['image' => 'Use a valid JPEG, PNG or WebP, at most 5 MiB, 4096 pixels per side and 8 million pixels.']);
        if (! in_array(strtolower($file->getClientOriginalExtension()), ['jpg', 'jpeg', 'png', 'webp'], true) || ! $file->isValid() || $file->getSize() > 5 * 1024 * 1024) {
            $fail();
        }
        $bytes = file_get_contents($file->getRealPath());
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $size = @getimagesizefromstring($bytes);
        $types = ['image/jpeg' => IMAGETYPE_JPEG, 'image/png' => IMAGETYPE_PNG, 'image/webp' => IMAGETYPE_WEBP];
        if (! isset($types[$mime]) || ! $size || $size[2] !== $types[$mime] || $size[0] < 1 || $size[1] < 1 || max($size[0], $size[1]) > 4096 || $size[0] * $size[1] > 8000000) {
            $fail();
        }
        // Reject decoder warnings, including truncated files GD might otherwise repair.
        set_error_handler(static fn () => throw new \RuntimeException('Invalid raster'));
        try {
            $source = imagecreatefromstring($bytes);
        } catch (\Throwable) {
            $fail();
        } finally {
            restore_error_handler();
        }
        if (! $source) {
            $fail();
        }
        $scale = min(1, 2048 / max($size[0], $size[1]));
        $width = max(1, (int) round($size[0] * $scale));
        $height = max(1, (int) round($size[1] * $scale));
        $canvas = imagecreatetruecolor($width, $height);
        try {
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $width, $height, $size[0], $size[1]);
            ob_start();
            try {
                if (! imagejpeg($canvas, null, 85)) {
                    throw new \RuntimeException('Image encoding failed');
                }
                $encoded = ob_get_contents();
            } finally {
                ob_end_clean();
            }
        } finally {
            imagedestroy($source);
            imagedestroy($canvas);
        }

        return ['content' => $encoded, 'width' => $width, 'height' => $height, 'bytes' => strlen($encoded)];
    }

    public function newPath(): string
    {
        return Str::uuid().'.jpg';
    }

    public function put(string $path, string $content): void
    {
        if (! Storage::disk('reclaim_images')->put($path, $content)) {
            throw new \RuntimeException('Image storage failed');
        }
    }

    public function remove(string $path): void
    {
        // Cleanup must never turn a committed mutation into an apparent failure.
        try {
            if (! Storage::disk('reclaim_images')->delete($path)) {
                throw new \RuntimeException('Image cleanup failed');
            }
        } catch (\Throwable $error) {
            Log::warning('Unreferenced image needs cleanup', ['path' => $path, 'error' => get_class($error)]);
        }
    }

    public function content(string $path): string
    {
        abort_unless(Storage::disk('reclaim_images')->exists($path), 404);

        return Storage::disk('reclaim_images')->get($path);
    }
}
