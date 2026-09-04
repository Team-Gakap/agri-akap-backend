<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class FacebookGraphService
{
    public function isConfigured(): bool
    {
        $pageId = config('services.facebook.page_id');
        $token = config('services.facebook.page_access_token');

        return filled($pageId) && filled($token);
    }

    /**
     * Upload a PNG to the Facebook Page photos endpoint.
     *
     * @return array{post_id:string,id:?string}
     */
    public function postPhoto(string $pngBinary, string $caption): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Facebook Page is not configured. Set FACEBOOK_PAGE_ID and FACEBOOK_PAGE_ACCESS_TOKEN.');
        }

        $pageId = (string) config('services.facebook.page_id');
        $token = (string) config('services.facebook.page_access_token');
        $version = (string) config('services.facebook.graph_version', 'v21.0');
        $base = rtrim((string) config('services.facebook.graph_base_url', 'https://graph.facebook.com'), '/');

        $tmp = tempnam(sys_get_temp_dir(), 'fbcard_');
        if ($tmp === false) {
            throw new RuntimeException('Unable to create temporary file for Facebook upload.');
        }
        $path = $tmp.'.png';
        rename($tmp, $path);
        file_put_contents($path, $pngBinary);

        try {
            $response = Http::timeout(60)
                ->attach('source', file_get_contents($path) ?: '', 'rainfall-forecast.png')
                ->post("{$base}/{$version}/{$pageId}/photos", [
                    'caption' => $caption,
                    'access_token' => $token,
                    'published' => 'true',
                ]);
        } finally {
            @unlink($path);
        }

        if (! $response->successful()) {
            Log::warning('Facebook Graph photo upload failed', [
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);

            $message = data_get($response->json(), 'error.message')
                ?? 'Facebook rejected the photo upload.';

            throw new RuntimeException($message);
        }

        $data = $response->json() ?? [];
        $postId = (string) ($data['post_id'] ?? $data['id'] ?? '');
        if ($postId === '') {
            throw new RuntimeException('Facebook returned no post id.');
        }

        return [
            'post_id' => $postId,
            'id' => isset($data['id']) ? (string) $data['id'] : null,
        ];
    }
}
