<?php

namespace App\Providers;

use App\Support\CurrentSite;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CurrentSite::class);
    }

    public function boot(): void
    {
        RateLimiter::for('site-api', function (Request $request) {
            $siteId = $request->attributes->get('site')?->id ?? $request->ip();

            return Limit::perMinute(config('gsai.rate_limit_per_minute'))->by("site:{$siteId}");
        });
    }
}
