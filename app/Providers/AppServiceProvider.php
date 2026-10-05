<?php

namespace App\Providers;

use App\Auth\LoginThrottle;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Observers\RoleObserver;
use App\Observers\SystemSettingObserver;
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
use Throwable;

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
        //
        // The warming depends on the LAYOUT: app.blade.php includes the sidebar
        // first, so `activeMap()` has resolved the whole catalogue by the time
        // this runs. Measured, cold cache, flushing Pennant between requests —
        // sidebar first: 1 feature-store read per page; header first: 2.
        view()->composer('layouts.partials.header', function (View $view): void {
            $view->with('sessionsVisible', FeatureCatalog::isActive('sessions'));

            // The bell points at the notifications module, so it carries the SAME two gates
            // the sidebar item does. Permission included: until P9-C2 replaces this
            // target with the inbox, the destination is `notifications.index`,
            // which a plain user is refused by — and a bell that 403s on click is
            // worse than the dead button it replaced.
            //
            // Read from the same catalog and the same Gate as the sidebar, so
            // header and sidebar cannot disagree about whether the module is
            // reachable for this viewer.
            //
            // P9-C2 moves the target to the inbox, which is the viewer's OWN rows
            // and needs no permission — the bell then drops this check and keeps
            // only the flag.
            $view->with(
                'notificationsVisible',
                FeatureCatalog::isActive('notifications')
                && (auth()->user()?->can('notifications.view') ?? false)
            );
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
        SystemSetting::observe(SystemSettingObserver::class);

        // The cached user count masks superadmin accounts, and that mask joins
        // through `model_has_roles` + `roles` — so it depends on role data, not
        // only on the users table. Without this, a role RENAME changed who the
        // mask excluded and no observer noticed. `RoleAssignAction` still has to
        // invalidate itself: `syncRoles()` writes the pivot and fires no model
        // event on either side.
        Role::observe(RoleObserver::class);


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
     * Push the stored token lifetimes onto the runtime config.
     *
     * `config/auth.php` reads `env('PASSWORD_RESET_EXPIRE_MINUTES')`, which no
     * admin can change from the UI. Storing the value in `system_settings` while
     * the broker kept using the .env meant the field looked live, returned 200 on
     * save, and changed nothing — the worst way for a settings field to fail.
     *
     * ## Why this is NOT called from boot()
     *
     * Boot runs before the settings table exists on a fresh install, and before
     * `RefreshDatabase` migrates in tests. Reading there is not a matter of
     * catching an exception — the query failure takes the whole boot down with
     * it. Every other `SystemSetting::getInt()` in these providers sits inside a
     * throttle closure that runs per request, never at boot, and that is the
     * reason they are safe.
     *
     * So binding happens at the one moment that matters: a settings save.
     * `SystemSettingsUpdateAction` calls this right after its transaction
     * commits, so an admin who changes the lifetime sees it enforced in the same
     * process that wrote it, with no restart and no deploy.
     *
     * A process that only ever reads — a worker draining a queue of password
     * resets, a console command — keeps whatever the last write bound, or
     * `config/auth.php`'s default if that process never saw one. That is the
     * `ponytail` ceiling, and it is named here rather than hidden: a long-lived
     * worker started before a settings change enforces the old lifetime until it
     * is restarted. Resolving these per call rather than from config removes the
     * ceiling at the cost of a query per reset link; it is not worth paying for a
     * value that changes perhaps monthly.
     */
    public static function bindTokenExpirations(): void
    {
        // The try/catch is not defensive noise: boot runs during `migrate` and
        // `config:cache`, before the table necessarily exists.
        try {
            config()->set([
                'auth.passwords.users.expire' => SystemSetting::getInt('password_reset_expire_minutes', 15),
                'auth.verification.expire' => SystemSetting::getInt('email_verification_expire_minutes', 60),
            ]);
        } catch (Throwable $e) {
            // No settings table yet — leave the config defaults in place.
        }
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }
}
