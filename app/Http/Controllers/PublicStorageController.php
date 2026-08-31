<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublicStorageController extends Controller
{
    /**
     * Stream a public-disk file when public/storage is not symlinked
     * (Railway, Windows). Same URLs the frontend already uses: /storage/{path}.
     */
    public function show(string $path): StreamedResponse
    {
        $path = str_replace('\\', '/', $path);
        if ($path === '' || str_contains($path, '..')) {
            abort(404);
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($path)) {
            abort(404);
        }

        return $disk->response($path);
    }
}
