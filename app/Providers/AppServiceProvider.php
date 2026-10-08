<?php

namespace App\Providers;

use App\Auth\LoginThrottle;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Support\UnreadNotificationCount;
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
use Illuminate\Support\Facades\Log;
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

            // The bell points at the inbox, so it follows the INBOX's gate — the
            // flag only, no permission. It previously carried
            // `notifications.view` because its target was the configuration page,
            // which is permission-gated; the inbox is every user's own rows, and
            // keeping the check would hide the bell from exactly the people an
            // inbox is for.
            $view->with('notificationsVisible', FeatureCatalog::isActive('notifications'));

            // The unread count, read HERE rather than in the partial: the partial
            // renders on every authenticated page and a count read in its markup
            // is a query per render.
            //
            // Cached, so it is a query on a cold cache rather than on every
            // render — see `UnreadNotificationCount` for why it is invalidated on
            // the notification event and not when the inbox is opened.
            //
            // a flag-off module leaves no icon pointing at a 403. The unread
            // badge and the inbox target land with P9-C2; until then it points
            // at the inbox, which every authenticated user can open.
            $show = auth()->check();

            $view->with([
                'unreadNotificationCount' => $show ? UnreadNotificationCount::for(auth()->user()) : 0,
                'recentNotifications' => $show ? UnreadNotificationCount::recent(auth()->user()) : collect(),
            ]);
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

        // The bell's cached count is invalidated when a notification is delivered,
        // not when the inbox is opened — see the class for why the second is too
        // late to be correct.
        UnreadNotificationCount::listen();

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

        // Sending test mail is the one action in the app that puts a message on
        // someone else's server with an address the caller typed.
        // `notifications.send_test` is already a separate permission from
        // `notifications.manage`, but a permission answers "may this person" and
        // not "how often" — without a ceiling, a compromised operator account
        // turns this route into a mail relay pointed at any third party.
        //
        // Keyed on the recipient address as well as the operator, so the limit
        // holds even when one account sprays many different addresses.
        RateLimiter::for('send-test-mail', function ($request) use ($throttle) {
            $to = strtolower(trim((string) $request->input('email', '')));
            $key = $throttle->key('send-test-mail', (string) $request->user()?->id, $request->ip())
                .':'.$to;

            return Limit::perMinute(5)
                ->by($key)
                ->response($throttle->responseFor('email'));
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
     * Push the stored mail transport onto the runtime config.
     *
     * `config/mail.php` reads `env('MAIL_HOST')` and friends, which no admin can
     * change from the UI. Storing the value in `system_settings` while the
     * transport kept using the .env is the same failure `bindTokenExpirations()`
     * documents for token lifetimes: the field looks live, the save returns 200,
     * and nothing changes.
     *
     * `.env` is the fallback for every read, so an install that never saved a
     * row behaves exactly as it did before this existed.
     *
     * ## Called at the same moment as bindTokenExpirations(), for the same reason
     *
     * Not from boot(): boot runs before the settings table exists on a fresh
     * install and before `RefreshDatabase` migrates in tests, and the query
     * failure takes the whole boot down. Binding happens after a save commits,
     * so the admin who saved sees it enforced in the same process.
     *
     * `scheme`, not `encryption`: `config/mail.php` declares no `encryption` key
     * on the smtp mailer, so a stored value written under that name would be a
     * row nothing reads. See the phase doc's D-1 divergence.
     *
     * ponytail: a queue worker started before a transport change keeps enforcing
     * the transport it bound until it is restarted (`queue:restart`). Accepted
     * for the reason it is accepted for token lifetimes — a value that changes
     * perhaps quarterly is not worth a query per outgoing mail.
     */
    public static function bindMailConfig(): void
    {
        // The try/catch matches bindTokenExpirations(): `config:cache` and a
        // console command can reach this before the table does.
        try {
            $smtp = 'mail.mailers.smtp';

            // Cast every config fallback to string/int before it reaches the
            // typed getters. `config('mail.mailers.smtp.username')` is `null`
            // whenever `MAIL_USERNAME` is unset — which is most installs, and
            // every install using a relay that needs no authentication — and
            // `getString(string $key, string $default)` rejects null with a
            // TypeError. The exception then landed in the catch below and NO
            // key was bound, so a perfectly good configuration silently left the
            // transport on its .env values. A single unconfigured field took the
            // whole binding down.
            $text = fn (string $path): string => (string) (config($path) ?? '');

            config()->set([
                'mail.default' => SystemSetting::getString('mail_mailer', $text('mail.default')),

                // Read as one array and written back with one spread: writing six
                // `mail.mailers.smtp.*` keys means six `config()->set()` calls
                // that each have to remember they are editing one nested array.
                $smtp => array_replace(config($smtp) ?? [], [
                    'host' => SystemSetting::getString('mail_host', $text($smtp . '.host')),
                    'port' => SystemSetting::getInt('mail_port', (int) ($text($smtp . '.port') ?: 2525)),
                    'username' => SystemSetting::getString('mail_username', $text($smtp . '.username')),
                    'password' => self::mailPassword(SystemSetting::getString('mail_password', '')),
                    // No fallback argument at all: an unconfigured scheme must
                    // stay null (plaintext relay), not inherit the .env value —
                    // an operator who stored `none` chose that, and re-reading
                    // MAIL_SCHEME here would silently re-enable the TLS they
                    // turned off.
                    'scheme' => SystemSetting::getString('mail_encryption', '') ?: null,
                ]),

                'mail.from' => [
                    'address' => SystemSetting::getString('mail_from_address', $text('mail.from.address')),
                    'name' => SystemSetting::getString('mail_from_name', $text('mail.from.name')),
                ],
            ]);
        } catch (Throwable $e) {
            // Logged, not swallowed. A silent catch here makes a broken binding
            // indistinguishable from a transport nobody configured: the admin saves
            // a host, the page reloads showing the old one, and nothing says why.
            // `bindTokenExpirations()` may swallow because its failure means "no
            // table yet", which is expected during migrate. This one would be a real
            // bug, so it has to be visible.
            Log::warning('Could not bind the stored mail configuration', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The stored SMTP password, decrypted, or null when there is none.
     *
     * A separate method because the credential is the one setting whose stored
     * form is NOT its usable form: `mail_password` holds `encrypt()` output, so
     * anything reading it raw gets ciphertext. Decrypting where it is written
     * means the transport gets a usable password and no other caller can
     * accidentally take the raw column for one.
     *
     * `decrypt()` throws on a value that was never encrypted — a row written
     * before this existed, or by hand. That is caught by the caller's
     * try/catch and falls back to the .env credential, which is the right
     * outcome: a value we cannot read is not a value we should send.
     */
    private static function mailPassword(string $stored): ?string
    {
        return $stored === '' ? null : decrypt($stored);
    }

    /**
     * Whether a mail password is stored, without revealing it.
     *
     * The view renders `$hasPassword` rather than the credential — an SMTP
     * password echoed into the markup is readable by everyone who can open the
     * page, which is the exact audience it exists to hide from.
     */
    public static function hasMailPassword(): bool
    {
        return SystemSetting::getString('mail_password') !== '';
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }
}
