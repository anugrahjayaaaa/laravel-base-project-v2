<?php

namespace App\Providers;

use App\Auth\LoginThrottle;
use App\Models\User;
use App\Observers\UserObserver;
use App\Services\PasswordExpiry;
use App\Support\FeatureCatalog;
use App\View\Composers\AccountOptionsComposer;
use App\View\Composers\AppMenuComposer;
use App\View\Composers\PasswordStrengthComposer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Laravel\Pennant\Feature;

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
        // The header dropdown carries its own Sessions link, outside $menuGroups,
        // and reads ONE boolean — so it gets a closure, not the full composer.
        // Registering AppMenuComposer here ran a second complete compose() on
        // every authenticated page: eleven menu items rebuilt, five Gate lookups,
        // all discarded for one flag. That was the single largest avoidable cost
        // on the page after the N+1 fixes landed.
        //
        // It answers the same question from the same source, so the sidebar and
        // the dropdown still cannot disagree — and Pennant's in-request cache
        // means the sidebar's read has already warmed this one to 0 queries.
        view()->composer('layouts.partials.header', function (View $view): void {
            $view->with('sessionsVisible', FeatureCatalog::isActive('sessions'));
        });
        view()->composer('layouts.partials.password-strength', PasswordStrengthComposer::class);

        // Roles and the identity-change policy: shared by every page that shows
        // an identity field, so they are not threaded through one controller at
        // a time. @include shares the parent scope, so the partials underneath
        // are covered by their caller.
        //
        // /settings is not on this list: its role dropdown wants a name list,
        // not Role models, so it keeps reading them itself.
        view()->composer([
            'pages.users.create',
            'pages.users.edit',
            'pages.profile.edit',
        ], AccountOptionsComposer::class);

        view()->composer('layouts.app', function ($view): void {
            $user = auth()->user();

            $view->with([
                'currentUserName' => $user?->name,
                'showPasswordExpiryWarning' => $user && PasswordExpiry::shouldWarn($user),
                'passwordExpiryDaysRemaining' => $user ? PasswordExpiry::daysUntilExpiry($user) : 0,
            ]);
        });

        User::observe(UserObserver::class);

        // Feature flags: one definition per catalogue entry.
        //
        // The resolver returns FALSE, and that is the load-bearing part.
        // Pennant asks the resolver only when the store has no row for a flag,
        // so `false` makes an unseeded flag read as OFF — fail-closed, which
        // is what `FeatureFlagCatalogTest::every_catalogued_flag_resolves_off_
        // until_it_is_seeded` pins. Returning `true` here (the obvious
        // "features are on by default") inverts that: a flag added to config
        // would be live before anyone seeded it, and the kill switch would
        // depend on the seeder having run.
        //
        // Measured, because it is not obvious from the docs: when a row DOES
        // exist, the store wins and the resolver is ignored. So `disabled =>
        // true` cannot be expressed here — a stored `true` would survive it.
        // That key is honoured by the project's own readers
        // (FeatureIndexAction now, EnsureFeatureIsEnabled at P7-C1), which is
        // the only place a flag's effective state is decided.
        //
        // Scope is forced global. Pennant defaults to the authenticated user,
        // which would make each flag per-account — then the management page
        // would show one user's answer and write it for everyone, and the
        // sidebar would disagree with the routes depending on who asked. A
        // kill switch is one switch.
        Feature::resolveScopeUsing(fn () => 'global');

        foreach (array_keys(config('pennant.features', [])) as $slug) {
            Feature::define($slug, fn (): bool => false);
        }

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
