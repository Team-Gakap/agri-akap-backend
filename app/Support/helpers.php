<?php

use Illuminate\Support\Facades\Storage;

if (! function_exists('public_storage_url')) {
    /**
     * HTTPS-safe public URL for a file on the public disk.
     * Accepts a relative path (farmer-photos/x.jpg) or a storage/ prefixed path.
     */
    function public_storage_url(?string $path): ?string
    {
        if (! filled($path)) {
            return null;
        }

        $path = trim($path);
        if (str_starts_with($path, 'data:')) {
            return $path;
        }

        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        $path = ltrim($path, '/');
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        return Storage::disk('public')->url($path);
    }
}
