<?php

namespace App\Providers;

use App\Services\Settings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Settings::class);
    }

    public function boot(): void
    {
        Password::defaults(fn () => Password::min(10)->letters()->numbers());

        // Xero allows 60 calls/minute per organisation; stay under it.
        RateLimiter::for('xero', fn () => Limit::perMinute(50));

        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }
    }
}
