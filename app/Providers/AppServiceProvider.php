<?php

namespace App\Providers;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        require_once app_path('Support/helpers.php');

        $this->app->singleton(\PragmaRX\Google2FA\Google2FA::class, function () {
            $google2fa = new \PragmaRX\Google2FA\Google2FA();
            $google2fa->setWindow(1);

            return $google2fa;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $appUrl = rtrim((string) config('app.url'), '/');
        if ($appUrl !== '') {
            URL::forceRootUrl($appUrl);
        }

        if ($this->app->environment('production') || str_starts_with($appUrl, 'https://')) {
            URL::forceScheme('https');
        }

        $this->ensurePublicStorageLink();
    }

    /**
     * public/storage → storage/app/public so /storage/* is web-reachable.
     * If the symlink cannot be created, PublicStorageController still serves files.
     */
    protected function ensurePublicStorageLink(): void
    {
        $link = public_path('storage');
        if (is_link($link) || file_exists($link)) {
            return;
        }

        try {
            Artisan::call('storage:link', ['--force' => true]);
        } catch (\Throwable $e) {
            Log::warning('Could not create public/storage link: '.$e->getMessage());
        }
    }
}
