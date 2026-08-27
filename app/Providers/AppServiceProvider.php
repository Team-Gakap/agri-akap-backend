<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
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
        //
    }
}
