<?php

namespace App\Providers;

use App\Models\User;
use App\Observers\UserObserver;
use App\View\Composers\AppMenuComposer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\RateLimiter;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Paginator::useBootstrap();
        LengthAwarePaginator::useBootstrap();

        view()->composer('layouts.partials.sidebar', AppMenuComposer::class);

        User::observe(UserObserver::class);

        RateLimiter::for('user-state-actions', function ($request) {
            $key = $request->user()?->id ?: $request->ip();

            return Limit::perMinute(15)->by($key);
        });
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }
}
