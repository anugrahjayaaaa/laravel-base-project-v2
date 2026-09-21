<?php

namespace App\Providers;

use App\Models\User;
use App\Observers\UserObserver;
use App\View\Composers\AppMenuComposer;
use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;
use Illuminate\Pagination\LengthAwarePaginator;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Paginator::useBootstrap();
        LengthAwarePaginator::useBootstrap();

        view()->composer('layouts.partials.sidebar', AppMenuComposer::class);

        User::observe(UserObserver::class);

        \Illuminate\Support\Facades\RateLimiter::for('user-state-actions', function ($request) {
            $key = $request->user()?->id ?: $request->ip();

            return \Illuminate\Cache\RateLimiting\Limit::perMinute(15)->by($key);
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
