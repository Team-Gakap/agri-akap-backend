<?php

namespace App\Services;

use App\Models\FacebookWeatherPost;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class FacebookRainfallCardService
{
    public function __construct(
        private RainfallForecastComposer $composer,
        private RainfallForecastCardRenderer $renderer,
        private FacebookGraphService $facebook,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(string $window): array
    {
        $payload = $this->composer->compose($window);
        $png = $this->renderer->render($payload);
        $path = $this->storePng($png, $payload['forecast_date'], $payload['window']);

        $already = FacebookWeatherPost::query()
            ->whereDate('forecast_date', $payload['forecast_date'])
            ->where('window', $payload['window'])
            ->latest()
            ->first();

        return [
            'window' => $payload['window'],
            'forecast_date' => $payload['forecast_date'],
            'validity_label' => $payload['validity_label'],
            'issued_at' => $payload['issued_at'],
            'issued_label' => $payload['issued_label'],
            'title' => $payload['title'],
            'subtitle' => $payload['subtitle'],
            'attribution' => $payload['attribution'],
            'barangays' => $payload['barangays'],
            'legend' => $payload['legend'],
            'caption' => $payload['caption'],
            'has_data' => $payload['has_data'],
            'image_path' => $path,
            'image_url' => public_storage_url($path),
            'facebook_configured' => $this->facebook->isConfigured(),
            'already_posted' => $already !== null,
            'last_post' => $already ? $this->transformPost($already) : null,
        ];
    }

    public function renderPng(string $window): string
    {
        $payload = $this->composer->compose($window);

        return $this->renderer->render($payload);
    }

    /**
     * @return array{post:array<string,mixed>,facebook:array{post_id:string,id:?string},warning:?string}
     */
    public function post(string $window, ?string $caption, ?User $actor, bool $force = false): array
    {
        if (! $this->facebook->isConfigured()) {
            throw new RuntimeException('Facebook Page is not configured. Set FACEBOOK_PAGE_ID and FACEBOOK_PAGE_ACCESS_TOKEN on the server.');
        }

        $payload = $this->composer->compose($window);
        if (! $payload['has_data']) {
            throw new RuntimeException('No weather cache rows for this forecast date. Run weather:sync-all first.');
        }

        $existing = FacebookWeatherPost::query()
            ->whereDate('forecast_date', $payload['forecast_date'])
            ->where('window', $payload['window'])
            ->latest()
            ->first();

        if ($existing && ! $force) {
            throw new InvalidArgumentException(
                'This forecast window was already posted. Pass force=true to post again.'
            );
        }

        $caption = filled($caption) ? trim((string) $caption) : $payload['caption'];
        if ($caption === '') {
            throw new InvalidArgumentException('Caption cannot be empty.');
        }

        $png = $this->renderer->render($payload);
        $path = $this->storePng($png, $payload['forecast_date'], $payload['window']);

        $fb = $this->facebook->postPhoto($png, $caption);

        $post = FacebookWeatherPost::create([
            'forecast_date' => $payload['forecast_date'],
            'window' => $payload['window'],
            'caption' => $caption,
            'image_path' => $path,
            'facebook_post_id' => $fb['post_id'],
            'posted_by' => $actor?->id,
        ]);

        return [
            'post' => $this->transformPost($post->load('poster')),
            'facebook' => $fb,
            'warning' => $existing ? 'Reposted the same forecast window.' : null,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function recentPosts(int $limit = 20): array
    {
        return FacebookWeatherPost::query()
            ->with('poster:id,name')
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (FacebookWeatherPost $post) => $this->transformPost($post))
            ->all();
    }

    protected function storePng(string $png, string $forecastDate, string $window): string
    {
        $name = sprintf(
            'weather-cards/%s-%s-%s.png',
            $forecastDate,
            $window,
            Str::lower(Str::random(8))
        );

        Storage::disk('public')->put($name, $png);

        return $name;
    }

    /**
     * @return array<string, mixed>
     */
    protected function transformPost(FacebookWeatherPost $post): array
    {
        return [
            'id' => $post->id,
            'forecast_date' => $post->forecast_date?->toDateString(),
            'window' => $post->window,
            'caption' => $post->caption,
            'image_path' => $post->image_path,
            'image_url' => public_storage_url($post->image_path),
            'facebook_post_id' => $post->facebook_post_id,
            'posted_by' => $post->posted_by,
            'posted_by_name' => $post->poster?->name,
            'created_at' => optional($post->created_at)?->timezone(WeatherService::TIMEZONE)->toIso8601String(),
        ];
    }
}
