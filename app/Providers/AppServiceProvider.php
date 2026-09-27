<?php

namespace App\Providers;

use App\Auth\LoginThrottle;
use App\Models\User;
use App\Observers\UserObserver;
use App\Services\PasswordExpiry;
use App\View\Composers\AppMenuComposer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Application service provider.
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        Paginator::useBootstrap();
        LengthAwarePaginator::useBootstrap();

        view()->composer('layouts.partials.sidebar', AppMenuComposer::class);

        view()->composer('layouts.app', function ($view): void {
            $user = auth()->user();

            $view->with([
                'showPasswordExpiryWarning' => $user && PasswordExpiry::shouldWarn($user),
                'passwordExpiryDaysRemaining' => $user ? PasswordExpiry::daysUntilExpiry($user) : 0,
            ]);
        });

        User::observe(UserObserver::class);

        // Both are reachable from the API, so both need the JSON branch too.
        $throttle = app(LoginThrottle::class);

        RateLimiter::for('user-state-actions', function ($request) use ($throttle) {
            $key = $request->user()?->id ?: $request->ip();

            return Limit::perMinute(15)
                ->by($key)
                ->response($throttle->responseFor('user'));
        });

        RateLimiter::for('bulk-action', function ($request) use ($throttle) {
            $key = $request->user()?->id ?: $request->ip();

            return Limit::perMinute(5)
                ->by($key)
                ->response($throttle->responseFor('users'));
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
