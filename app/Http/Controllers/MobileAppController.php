<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MobileAppController extends Controller
{
    private const APK_PATH = 'apk/AGRI-AKAP-Technician.apk';

    public function version(): JsonResponse
    {
        return response()->json([
            'latest_version' => config('mobile.version'),
            'apk_url' => route('api.mobile.download-apk'),
            'force_update' => (bool) config('mobile.force_update'),
            'apk_available' => Storage::disk('public')->exists(self::APK_PATH),
        ]);
    }

    public function download(): StreamedResponse|JsonResponse
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('public');

        if (! $disk->exists(self::APK_PATH)) {
            return response()->json([
                'status' => 'error',
                'message' => 'The technician app is not available for download yet.',
            ], 404);
        }

        return $disk->download(
            self::APK_PATH,
            'AGRI-AKAP-Technician.apk',
            ['Content-Type' => 'application/vnd.android.package-archive'],
        );
    }
}
