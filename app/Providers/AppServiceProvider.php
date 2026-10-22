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
use App\Support\MorphMap;
use App\Support\OpenApi\ExportedDocument;
use App\Support\Vantage\FailedJobPayloadRestorer;
use App\View\Composers\AccountOptionsComposer;
use App\View\Composers\AppMenuComposer;
use App\View\Composers\PasswordStrengthComposer;
use Dedoc\Scramble\CacheableGenerator;
use Dedoc\Scramble\Generator;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Laravel\Pennant\Feature;
use Laravel\Pulse\Facades\Pulse;
use Storvia\Vantage\Support\JobRestorer;
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

        // Every process enforces the stored token lifetimes, not only the one
        // that saved them: `config/auth.php` and `config/sanctum.php` read env
        // values, and a settings row nothing binds is a field that saves 200 and
        // changes nothing. Boot is the one moment early enough to cover the HTTP
        // request, the queue worker and `sanctum:prune-expired` alike.
        //
        // Cheap: `SystemSetting::loadSettings()` is one cache read for the whole
        // map, and the try/catch inside is what makes it safe during `migrate`
        // and `config:cache`, where the table does not exist yet.
        self::bindExpirations();

        // The correlation id travels with the job, carried in the payload.
        //
        // `GenerateRequestCorrelationId` binds `request_id` on the REQUEST;
        // the worker that later runs the job is a different process where that
        // binding never happened, so the container read the rest of the
        // application uses (`app()->bound('request_id')`) answers null exactly
        // where the correlation matters most — a failed export is queued work,
        // not the request that asked for it. Capturing the id at PUSH time,
        // still inside the request, is what lets `Jobs\Concerns\ReadsCorrelationId`
        // answer it later from the payload.
        //
        // Null stays valid and present: a sweep dispatched by the scheduler has
        // no request behind it, and jobs queued before this hook existed read
        // null the same way — the key is merged at the payload's top level.
        Queue::createPayloadUsing(static fn (): array => [
            'request_id' => app()->bound('request_id') ? app('request_id') : null,
        ]);

        // The nonce itself is per-response: `AddSecurityHeaders` shares a fresh
        // one on every request, and only that request's views are rendered
        // while it holds the real value. (The policy is attached to `text/html`
        // only; the share runs unconditionally because the middleware does.)
        //
        // The default exists because a view is also rendered by things that are
        // not an HTTP response at all — a mail body, a queue job, `view()->render()`
        // inside a test — and none of those pass through the middleware. Without a
        // value here `@nonce` there is an "Undefined variable" error, so one shared
        // variable turns "no policy applies" into a crash. An empty nonce is never
        // served under a real policy, because the header and the value come from
        // the same middleware.
        view()->share('cspNonce', '');

        // `<script @nonce>` rather than `nonce="{{ $cspNonce }}"` at every call
        // site: the attribute name and the variable name then cannot drift apart.
        //
        // The value is resolved at COMPILE time, not at run time: a bare `@nonce`
        // reaches here with an empty expression, and building `( ?: $cspNonce)`
        // into the compiled output is a PHP parse error in every view that uses
        // the directive — a fatal a unit test would never see until a page renders.
        //
        // `\\e` rather than `\e`: this is a DOUBLE-QUOTED PHP string, where `\e`
        // is PHP's own escape for the ESC control character — the compiled view
        // then reads `.0x1B($cspNonce)` and every page using it dies with a
        // ParseError, which is how this shipped once already.
        //
        // Registered HERE and not in `bootstrap/app.php`, because `withMiddleware()`
        // runs while the application is still being built and the Blade facade has
        // no root to resolve against yet — calling it there fatals the console and
        // every request with "A facade root has not been set".
        Blade::directive('nonce', function (string $expression): string {
            $value = $expression === '' ? '$cspNonce' : $expression;

            return "<?php echo 'nonce=\"'.\\e({$value}).'\"'; ?>";
        });

        // Pulse's root layout is `<x-pulse>`, and the package resolves it through
        // an `anonymousComponentPath` whose hash namespace points ONLY at the
        // vendor directory — `resources/views/vendor/pulse/` overrides never reach
        // it (unlike `x-pulse::theme-switcher`, which resolves via the `pulse`
        // view namespace where app paths win). Registering the tag as an alias
        // pointing at that namespace re-routes `x-pulse` to the overridden view;
        // `Blade::component`'s view branch is the framework's own escape hatch
        // (`componentClass()` checks `$viewFactory->exists($alias)`). Only the
        // exact tag `x-pulse` is affected — namespaced `x-pulse::*` tags have
        // their own lookup keys and still resolve normally.
        Blade::component('pulse::components.pulse', 'pulse');

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
        // Registered against the LIST partial as well as the header, because the
        // poll endpoint returns that partial on its own and it would otherwise
        // render with no `$recentNotifications` — the same class of bug
        // `AppMenuComposer` exists to prevent, one level down.
        view()->composer(
            ['layouts.partials.header', 'layouts.partials.notification-dropdown-list'],
            function (View $view): void {
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
            }
        );
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

        // Both monitoring recorders follow their flag — at CALL time, not at
        // boot. Until now the flag guarded only the dashboard route, so an
        // operator switching Pulse off to stop the write load during an
        // incident stopped the page and kept the writer: the package decides
        // whether to record once, in its own `boot()`, from `PULSE_ENABLED`.
        //
        // `Pulse::filter()` is the package's supported extension point and it is
        // consulted at INGEST, so the store is read when there is something to
        // write and never on a request that writes nothing. It reaches the
        // worker too, because ingestion happens in both processes.
        //
        // `PULSE_ENABLED=false` is untouched and stays the deploy-time master
        // switch: with it off, Pulse registers no recorders at all and this
        // filter is never asked.
        //
        // Not read at boot, deliberately — see `bindExpirations()` and its
        // comment. Boot runs before the `features` table exists on a fresh
        // install, and a store read there takes the whole boot down with it.
        Pulse::filter(fn (): bool => FeatureCatalog::isActive('pulse'));

        // Vantage offers no such hook: its recorders re-read
        // `config('vantage.enabled')` inside `handle()`, so that config key IS
        // the call-time switch and something has to set it before the work.
        //
        // `Looping`, not `Queue::before` / `JobProcessing`: the package listens
        // on `JobProcessing` and registered first (packages boot before app
        // providers — see the boot-order section in AGENTS.md), so a listener of
        // ours on the same event would run AFTER it and this switch would always
        // be one job behind. `Looping` fires once per daemon iteration, before
        // the job is fetched — a worker pays one flag read per job for that, and
        // it is the only way to ask the question at call time.
        //
        // ponytail: a worker started before the flip applies it from its next
        // loop, and `queue:listen`/a synchronous connection never fires
        // `Looping` at all — those keep `VANTAGE_ENABLED` as their only switch.
        // `bin/run-workers.sh` runs `queue:work`, which is the shape this covers.
        Event::listen(Looping::class, function (): void {
            config(['vantage.enabled' => FeatureCatalog::isActive('vantage')]);
        });

        // The audit table's polymorphic columns store an ALIAS instead of
        // `App\Models\Whatever`, so renaming a model does not orphan every
        // historical row pointing at it. Permissive on purpose — see
        // `App\Support\MorphMap` for why `enforceMorphMap()` is the wrong call on
        // a write path that records what just happened.
        Relation::morphMap(MorphMap::ALIASES);

        // Both are reachable from the API, so both need the JSON branch too.
        $throttle = app(LoginThrottle::class);

        RateLimiter::for('user-state-actions', function ($request) use ($throttle) {
            $key = $request->user()?->id ?: $request->ip();

            return Limit::perMinute(SystemSetting::getInt('user_state_actions_rate_limit_per_minute', 15))
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

            return Limit::perMinute(SystemSetting::getInt('send_test_mail_rate_limit_per_minute', 5))
                ->by($key)
                ->response($throttle->responseFor('email'));
        });

        RateLimiter::for('bulk-action', function ($request) use ($throttle) {
            $key = $request->user()?->id ?: $request->ip();

            return Limit::perMinute(SystemSetting::getInt('bulk_action_rate_limit_per_minute', 5))
                ->by($key)
                ->response($throttle->responseFor('users'));
        });

        // One audit export streams the whole filtered table into a file. Five an
        // hour is roughly an operator's working pace; a permission answers "may
        // this person" and not "how often", and without a ceiling a compromised
        // operator account turns this route into a lever against the database and
        // against disk — a worker burning its whole timeout on rows nobody reads.
        //
        // Keyed on user AND ip, so spraying across accounts does not widen it,
        // and per HOUR rather than per minute: the cost per call here is orders of
        // magnitude above the send-test-mail route it borrows the shape from.
        //
        // The ceiling is a `SystemSetting` (Phase 10, §11 P10-E9) rather than the
        // literal it was, because the audit page QUOTES this number to the operator
        // in a tooltip. A number shown in two places and stored in one is a number
        // that cannot drift; a literal behind a hardcoded label can, and the first
        // edit in the settings UI would have made the tooltip a lie.
        //
        // Read per request inside the closure, never at boot: boot runs before the
        // table exists on a fresh install, and this project has already been bitten
        // by that (`bindExpirations()` and its comment).
        //
        // The refusal rides on `format` because that is the one field this POST
        // carries — `responseFor()` flashes the field's value back so the filters
        // the user had chosen survive the redirect. On a field that is not in the
        // form the message would be attached to nothing the page renders.
        RateLimiter::for('audit-export', function ($request) use ($throttle) {
            $key = $throttle->key('audit-export', (string) $request->user()?->id, $request->ip());
            $perHour = SystemSetting::getInt('audit_export_rate_limit_per_hour', 5);

            return Limit::perHour($perHour)
                ->by($key)
                ->response($throttle->responseFor('format'));
        });

        // `GET /api/v1/health` is the one endpoint in the app with no gate of
        // any kind — no auth, no permission, no feature flag — because its
        // caller is a monitor that cannot log in (Phase 11 §F3). That decision
        // is right and this limiter does not revisit it. What it fixes is the
        // other half: nothing bounded the REQUEST COUNT either, so an anonymous
        // caller could buy an unbounded number of those seven round trips.
        //
        // Keyed on the IP alone, because there is no user to key on. That unit
        // is WEAK in a way the per-user limiters above are not: one address can
        // be a whole mobile carrier's NAT or a corporate egress, so the same
        // thousands of people share one counter. Hence 120/min (2/s) rather
        // than the 3-15/min those use — sixty times a monitor that polls every
        // thirty seconds, which is the slowest thing that can honestly be
        // called a client here. With the check set cached for ten seconds
        // (`HealthCheckService`), an allowed request is roughly one cache read,
        // so the ceiling is about keeping the count finite, not about metering.
        //
        // `jsonOnly`, because this route has no web branch: a 302 to the login
        // page reads as UP to a monitor that follows redirects.
        RateLimiter::for('health', function ($request) use ($throttle) {
            $key = $throttle->key('health', 'ip', $request->ip());

            return Limit::perMinute(SystemSetting::getInt('health_rate_limit_per_minute', 120))
                ->by($key)
                ->response($throttle->responseFor('health', jsonOnly: true));
        });

        // Re-queueing a failed job. `queues.retry` answers "may this person" and
        // not "how often", and this is the one mutating control on the queue
        // dashboard: a permission without a ceiling leaves a compromised
        // operator account able to re-dispatch work as fast as it can click.
        //
        // Keyed on user AND ip, like `audit-export`, and per MINUTE rather than
        // per hour because the unit of work is one job rather than a whole
        // table. Ten is well past an operator working through a failure list by
        // hand and far below what a script needs to matter.
        //
        // The `audit-export` limiter next door was the precedent this follows:
        // that one is a SystemSetting because the page quotes the number. This
        // one is a setting for the other half of the same reason — an operator
        // draining a caught-up backlog after an incident should be able to raise
        // it without a deploy.
        RateLimiter::for('queue-retry', function ($request) use ($throttle) {
            $key = $throttle->key('queue-retry', (string) $request->user()?->id, $request->ip());

            return Limit::perMinute(SystemSetting::getInt('queue_retry_rate_limit_per_minute', 10))
                ->by($key)
                // A flash, not a field error: the queue monitor is a vendor
                // layout that renders `session('error')` and has no `$errors`
                // bag, so the standard field-error response would reload the
                // page with no message on it.
                ->response($throttle->flashResponseFor(
                    'Too many job retries in a row. Please wait a moment and try again.'
                ));
        });
    }

    /**
     * Push the stored expire times onto the runtime config.
     *
     * `config/auth.php`, `config/sanctum.php` and `config/session.php` read env
     * values, which no admin can change from the UI. Storing the value in
     * `system_settings` while the consumer kept reading the .env meant the field
     * looked live, returned 200 on save, and changed nothing — the worst way for
     * a settings field to fail.
     *
     * ## Called from boot(), and again after every settings save
     *
     * Boot is what makes the stored value real for a process that did not write
     * it: a fresh PHP-FPM request, a worker, `sanctum:prune-expired`. The
     * try/catch below is what makes that safe — boot also runs during `migrate`
     * and `config:cache`, before the table exists, and the failure has to mean
     * "no settings yet", not a dead boot.
     *
     * The save-time call stays because a config value already read is not
     * re-read: the process handling the save keeps the old value until it exits.
     * `SystemSettingsUpdateAction` calls this after its transaction commits, so
     * the admin who saved sees it enforced without a restart or a deploy.
     *
     * A long-lived worker started before a settings change keeps the lifetime it
     * bound until it is restarted. Resolving these per call rather than from
     * config would remove that ceiling at the cost of a query per reset link and
     * per authenticated request; a value that changes perhaps monthly is not
     * worth it.
     */
    public static function bindExpirations(): void
    {
        // The try/catch is not defensive noise: boot runs during `migrate` and
        // `config:cache`, before the table necessarily exists.
        try {
            config()->set([
                'auth.passwords.users.expire' => SystemSetting::getInt('password_reset_expire_minutes', 15),
                'auth.verification.expire' => SystemSetting::getInt('email_verification_expire_minutes', 60),
                'sanctum.expiration' => self::lifetimeMinutes(
                    'api_token_expire_value',
                    'api_token_expire_unit',
                    '1',
                    'days',
                ),
                'session.lifetime' => self::lifetimeMinutes(
                    'web_session_expire_value',
                    'web_session_expire_unit',
                    '120',
                    'minutes',
                ),
            ]);
        } catch (Throwable $e) {
            // No settings table yet — leave the config defaults in place.
        }
    }

    /**
     * A stored lifetime as minutes: a value plus the unit it was entered in.
     *
     * The stored pair is the only authority. `config/sanctum.php` and
     * `config/session.php` carry plain defaults because config is evaluated
     * before the database and the cache exist — this method is what puts the
     * row in their place, and the settings page is what edits it.
     *
     * Stored as a pair (`minutes`, `hours`, `days`) because the sane answers
     * span three magnitudes — five minutes for a script, ninety days for a phone
     * that is rarely opened — and forcing every operator to convert 129600 by
     * hand is how a lifetime ends up mistyped. Every consumer here counts in
     * minutes: the Sanctum guard, the session middleware and the cookie it
     * writes. Sanctum compares `created_at` against it on every request, so it
     * is a ceiling that also applies to tokens already issued, not a promise
     * written at issue time.
     */
    private static function lifetimeMinutes(
        string $valueKey,
        string $unitKey,
        string $defaultValue,
        string $defaultUnit,
    ): int {
        $multiplier = match (SystemSetting::getString($unitKey, $defaultUnit)) {
            'minutes' => 1,
            'hours' => 60,
            default => 1440,
        };

        return max(1, SystemSetting::getInt($valueKey, (int) $defaultValue)) * $multiplier;
    }

    /**
     * Push the stored mail transport onto the runtime config.
     *
     * `config/mail.php` reads `env('MAIL_HOST')` and friends, which no admin can
     * change from the UI. Storing the value in `system_settings` while the
     * transport kept using the .env is the same failure `bindExpirations()`
     * documents for token lifetimes: the field looks live, the save returns 200,
     * and nothing changes.
     *
     * `.env` is the fallback for every read, so an install that never saved a
     * row behaves exactly as it did before this existed.
     *
     * ## Called at the same moment as bindExpirations(), for the same reason
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
        // The try/catch matches bindExpirations(): `config:cache` and a
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
            // `bindExpirations()` may swallow because its failure means "no
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
        // Vantage's retry re-dispatch lives inside its own controller and is
        // built from `vantage_jobs.payload`, a column `config/vantage.php`
        // keeps empty on purpose (`store_payload => false`, ADR-022). The
        // binding is the only hook the package leaves for that: the controller
        // resolves `JobRestorer` from the container at call time.
        //
        // It answers from the failed-job store the framework already keeps —
        // the record `queue:retry` reads — rather than a second copy of every
        // job's arguments. See `FailedJobPayloadRestorer` for the security
        // checks it reuses and for how the two stores' retentions differ.
        $this->app->bind(JobRestorer::class, FailedJobPayloadRestorer::class);

        // The API document is exported once per deploy (`scramble:export`) and
        // served from that file, instead of rebuilt on every visit. The
        // package's own `scramble:cache` was measured to save nothing: it
        // caches the analysis, and the ~700 ms is `spec()` building the array
        // from it. See `ExportedDocument` for the fallback when the file is
        // absent or broken.
        $this->app->bind(CacheableGenerator::class, fn ($app) => new ExportedDocument(
            $app->make(Generator::class),
            ExportedDocument::path(),
        ));
    }
}
