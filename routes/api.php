<?php

use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutAllController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\PasswordChangeController as ApiPasswordChangeController;
use App\Http\Controllers\Api\V1\Auth\PasswordForgotController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\VerifyEmailController;
use App\Http\Controllers\Api\V1\Auth\ResendVerificationController;
use App\Http\Controllers\Api\V1\Audit\AuditController;
use App\Http\Controllers\Api\V1\Feature\FeatureController;
use App\Http\Controllers\Api\V1\Notification\NotificationInboxController;
use App\Http\Controllers\Api\V1\Permission\PermissionController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\Role\RoleController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\Notification\NotificationController;
use App\Http\Controllers\Api\V1\SystemSettingController;
use App\Http\Controllers\Api\V1\User\UserController;
use App\Http\Controllers\Api\V1\User\UserStateController;
use App\Http\Controllers\Api\V1\HealthCheck\HealthCheckController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // ---------------------------------------------------------------------------
    // System health
    //
    // Ungated on purpose — the caller is a monitor that cannot log in — so the
    // ceiling on it is a rate limit rather than a permission, and the check set
    // behind it is cached. Both live in the limiter and the service; see
    // `RateLimiter::for('health')` and `HealthCheckService`.
    // ---------------------------------------------------------------------------
    Route::get('/health', HealthCheckController::class)->middleware('throttle:health');

    // ---------------------------------------------------------------------------
    // Public auth routes — no authentication required
    // ---------------------------------------------------------------------------
    Route::prefix('auth')->group(function () {
        Route::post('/login', LoginController::class)->name('api.v1.auth.login');
        Route::post('/register', RegisterController::class)->name('api.v1.auth.register')->middleware('throttle:register');
        Route::post('/password/forgot', PasswordForgotController::class)->name('api.v1.auth.password.forgot')->middleware('throttle:forgot-password');
        Route::post('/password/reset', PasswordResetController::class)->name('api.v1.auth.password.reset')->middleware('throttle:reset-password');
        Route::get('/email/verify/{id}/{hash}', VerifyEmailController::class)->name('api.v1.auth.email.verify')->middleware('signed');
    });

    // ---------------------------------------------------------------------------
    // Protected routes — Sanctum auth + email verified + non-expired password
    // ---------------------------------------------------------------------------
    Route::middleware(['auth:sanctum', 'verified', 'password.change.required', 'account.state', 'throttle:user-state-actions'])->group(function () {

        // Auth management. Same split as web.php: `logout` stays outside the
        // sessions gate so a bad flag cannot strand a user in a live session,
        // while `logout-all` is part of the sessions module and is gated. The
        // two call the same actions and write the same audit events, so an
        // ungated API route let a client mass-logout every device even with the
        // module switched off.
        Route::post('/auth/logout', LogoutController::class)->name('api.v1.auth.logout');
        Route::middleware('feature:sessions')->group(function () {
            Route::post('/auth/logout-all', LogoutAllController::class)->name('api.v1.auth.logout-all');
        });
        Route::post('/auth/email/resend', ResendVerificationController::class)->name('api.v1.auth.email.resend')->middleware('throttle:resend-verification');

        // Profile (authenticated user only)
        Route::controller(ProfileController::class)->group(function () {
            Route::get('/profile', 'show')->name('api.v1.profile.show');
            Route::put('/profile', 'update')->name('api.v1.profile.update');
        });

        // Sessions — flag `sessions`
        Route::middleware('feature:sessions')->group(function () {
            Route::controller(SessionController::class)->group(function () {
                Route::get('/sessions', 'index')->name('api.v1.sessions');
            });
        });

        // System Settings — gated to mirror the web matrix (P6-D2). These had no
        // gate at all, so any authenticated token could read every configured
        // value, including the registration and lockout defaults the admin panel
        // was supposed to hide.
        // System Settings — flag `settings`, mirroring the web matrix (P7-D8).
        Route::middleware('feature:settings')->group(function () {
            Route::controller(SystemSettingController::class)->group(function () {
                Route::get('/settings', 'index')->name('api.v1.settings.index')->can('settings.view');
                Route::put('/settings', 'update')->name('api.v1.settings.update')->can('settings.manage');
            });
        });

        // Notifications & Mail — Phase 9 Group B, mirroring the web matrix.
        //
        // The same three permissions on both channels: `.view` reads, `.manage`
        // writes the transport and the channel switches, `.send_test` may mail an
        // address the caller typed. `PUT`, not `POST`, matching
        // `api.v1.settings.update` — a partial update that replaces the transport
        // configuration, not a request to append to it.
        Route::middleware('feature:notifications')->group(function () {
            Route::controller(NotificationController::class)->group(function () {
                Route::get('/notifications', 'index')->name('api.v1.notifications.index')->can('notifications.view');
                Route::put('/notifications', 'update')->name('api.v1.notifications.update')->can('notifications.manage');
                Route::put('/notifications/channels', 'updateChannels')
                    ->name('api.v1.notifications.channels.update')->can('notifications.manage');
                // `throttle:send-test-mail` — a permission is not a rate limit, and this route
                // mails an address the caller typed.
                Route::post('/notifications/test-mail', 'sendTestMail')->middleware('throttle:send-test-mail')
                            ->name('api.v1.notifications.test-mail')->can('notifications.send_test');
            });

            // The INBOX, which the settings above are not: `/notifications` reads
            // the mail transport and the channel switches, so before this a
            // client could inspect which channels it had switched on and had no
            // way to see what they produced.
            //
            // Ungated, mirroring `/notifications/inbox` on the web. A
            // notification belongs to the person it was sent to, and the scoping
            // IS the user's own relation — "whose inbox is this" is answered by
            // the token, not by a permission name, so there is nothing here to
            // authorize.
            Route::controller(NotificationInboxController::class)->group(function () {
                Route::get('/notifications/inbox', 'index')->name('api.v1.notifications.inbox.index');
                // No `throttle:120,1` here, unlike the web twin. The whole
                // protected API group already carries `throttle:user-state-actions`
                // at 15/minute, which is TIGHTER, so a second limiter can never
                // be the one that refuses. Dead middleware on a route reads as a
                // control that is not there — the same failure as a feature flag
                // that renders "Inactive" over a route that still answers 200.
                //
                // The real ceiling is therefore 15/minute, not the web's 120. A
                // 30-second poll uses 2 of those, so it fits; a 5-second poll
                // would not, and that is a number to know before writing one.
                Route::get('/notifications/inbox/unread-count', 'unreadCount')
                    ->name('api.v1.notifications.inbox.unread-count');
                Route::post('/notifications/inbox/read-all', 'markAllAsRead')
                    ->name('api.v1.notifications.inbox.read-all');
                Route::post('/notifications/inbox/{id}/read', 'markAsRead')
                    ->name('api.v1.notifications.inbox.read');
            });
        });

        // Audit trail — Phase 10 follow-up (web/API gap audit).
        //
        // This module was browser-only on the READ side. Every WRITE already went
        // through the same Actions both channels share, so the gap was transport
        // and serialization, not logic — but a base project whose premise is that
        // the UI is replaceable cannot have its audit trail readable only in Blade.
        //
        // `audit.view` / `audit.export`, the same split the web routes carry:
        // reading the trail is not the same authority as taking it out of the
        // application. Both export routes are throttled by the SAME limiter the
        // web route uses, so a client cannot get a larger allowance by arriving
        // over HTTP instead of a browser.
        Route::middleware('feature:activity_logs')->group(function () {
            Route::controller(AuditController::class)->group(function () {
                Route::get('/activity-logs', 'index')->name('api.v1.activity-logs.index')->can('audit.view');

                // `whereNumber`, and it is load-bearing rather than tidy. This
                // route sits NEXT TO `/activity-logs/exports`, which is one
                // segment shorter than the download route and would otherwise be
                // swallowed by `{activity}` — a literal path losing to a
                // parameter because of registration order. Constraining the id to
                // what the column actually holds removes the trap for whatever
                // gets added under `/activity-logs/` next, instead of leaving a
                // route-ordering rule for someone to discover.
                Route::get('/activity-logs/{activity}', 'show')
                    ->whereNumber('activity')
                    ->name('api.v1.activity-logs.show')
                    ->can('audit.view');

                Route::post('/activity-logs/exports', 'storeExport')
                    ->name('api.v1.activity-logs.exports.store')
                    ->middleware(['can:audit.export', 'throttle:audit-export']);
                Route::get('/activity-logs/exports', 'exports')
                    ->name('api.v1.activity-logs.exports.index')
                    ->can('audit.view');

                // `audit.export` plus the ownership check inside the action, for
                // the same reason as the web route: the permission says "may
                // extract", not "may download extract #41", and the id here is
                // attacker-supplied. Same `audit-export` limiter as the POST, so
                // the API is not a way to a larger allowance.
                Route::get('/activity-logs/exports/{export}/download', 'downloadExport')
                    ->name('api.v1.activity-logs.exports.download')
                    ->middleware(['can:audit.export', 'throttle:audit-export']);

                // Re-run one failed export. Same permission and the SAME limiter,
                // for the reason the download shares it: HTTP is not a way to a
                // larger allowance, and a retry builds the same file.
                Route::post('/activity-logs/exports/{export}/retry', 'retryExport')
                    ->name('api.v1.activity-logs.exports.retry')
                    ->middleware(['can:audit.export', 'throttle:audit-export']);
            });
        });

        // User state management (Activate / Deactivate / Lock / Unlock)
        //
        // Ungated on the API as well as the web: the same controller, the same
        // User state management — flag `users`
        // actions, and neither checked anything, so a valid Sanctum token could
        // lock any account in the system.
        // Flag `users` — mirrors the web matrix (P7-D8). One group, not a
        // per-route call: a flag on 3 of 9 routes is a partial gate.
        Route::middleware('feature:users')->group(function () {
            Route::middleware(['throttle:user-state-actions'])->group(function () {
                Route::post('/users/{user}/activate', [UserStateController::class, 'activate'])->name('api.v1.users.activate')->can('users.activate');
                Route::post('/users/{user}/deactivate', [UserStateController::class, 'deactivate'])->name('api.v1.users.deactivate')->can('users.deactivate');
                Route::post('/users/{user}/lock', [UserStateController::class, 'lock'])->name('api.v1.users.lock')->can('users.lock');
                Route::post('/users/{user}/unlock', [UserStateController::class, 'unlock'])->name('api.v1.users.unlock')->can('users.unlock');
            });

            // User CRUD — resource + custom actions
            // Gated per-ability (P6-C14/C15/C18). The Form Requests already
            // authorize store/update/destroy; `can:` closes the reads, which nothing
            // was checking — before this, any authenticated token could list users.
            Route::get('/users', [UserController::class, 'index'])
                ->name('api.v1.users.index')->can('users.view');
            Route::get('/users/{user}', [UserController::class, 'show'])
                ->name('api.v1.users.show')->can('users.view');
            Route::post('/users', [UserController::class, 'store'])
                ->name('api.v1.users.store')->can('users.create');
            // Two route objects with distinct names: Scramble documents one
            // method per route object, so a single `match()` left PATCH absent
            // from the spec. Sharing a name breaks route:cache.
            Route::put('/users/{user}', [UserController::class, 'update'])
                ->name('api.v1.users.update')->can('users.update');
            Route::patch('/users/{user}', [UserController::class, 'update'])
                ->name('api.v1.users.patch')->can('users.update');
            Route::delete('/users/{user}', [UserController::class, 'destroy'])
                ->name('api.v1.users.destroy')->can('users.delete');
            Route::delete('/users/{user}/force', [UserController::class, 'forceDelete'])->name('api.v1.users.force-delete')->can('users.force_delete');
            Route::post('/users/{user}/restore', [UserController::class, 'restore'])->name('api.v1.users.restore')->can('users.restore');

            // Email change flow
            Route::post('/users/{user}/request-email-change', [UserController::class, 'requestEmailChange'])->name('api.v1.users.request-email-change');
            Route::post('/users/{user}/cancel-email-change', [UserController::class, 'cancelEmailChange'])->name('api.v1.users.cancel-email-change');
            Route::get('/email/verify-change/{user}', [UserController::class, 'verifyEmailChange'])->name('api.v1.email.verify-change')->middleware(['signed', 'throttle:email-verification']);
            Route::post('/users/{user}/resend-verification', [UserController::class, 'resendVerification'])->name('api.v1.users.resend-verification')->middleware('throttle:resend-verification')->can('users.update');
            // Same reasoning as the web bulk route: the permission depends on the
            // requested action, so AuthorizesBulkAction decides. See routes/web.php.
            Route::post('/users/bulk-action', [UserController::class, 'bulkAction'])->name('api.v1.users.bulk-action')->middleware('throttle:bulk-action');
        });


        // Role management — was browser-only, which meant a non-browser client
        // could administer users but could not administer the roles those users
        // hold. Same actions and Form Requests as the web controller, so the
        // system-role guards (P6-E3) apply here without being restated.
        //
        // `restore` and `force-delete` take an int rather than the model: the
        // default binding only finds LIVE rows, and a trashed role is the only
        // thing either endpoint accepts. The controllers use onlyTrashed().
        Route::middleware('feature:roles')->group(function () {
            Route::get('/roles', [RoleController::class, 'index'])->name('api.v1.roles.index')->can('roles.view');
            Route::post('/roles', [RoleController::class, 'store'])->name('api.v1.roles.store')->can('roles.create');
            Route::get('/roles/{role}', [RoleController::class, 'show'])->name('api.v1.roles.show')->can('roles.view');
            Route::put('/roles/{role}', [RoleController::class, 'update'])->name('api.v1.roles.update')->can('roles.update');
            Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('api.v1.roles.destroy')->can('roles.delete');
            Route::post('/roles/{role}/restore', [RoleController::class, 'restore'])->name('api.v1.roles.restore')->can('roles.restore');
            Route::delete('/roles/{role}/force', [RoleController::class, 'forceDelete'])->name('api.v1.roles.force-delete')->can('roles.force_delete');

            // Bulk — the counterpart the web surface had and this one did not, so a
            // non-browser client could retire a batch of roles only by calling the
            // single-role endpoint once per role.
            //
            // Same reasoning as `users.bulk-action` above: no `->can()` on the
            // route, because the permission depends on the requested action and
            // `BulkRoleRequest` decides it per action.
            //
            // Declared after `/roles/{role}` deliberately. It matches nothing
            // there — that route binds a model and accepts only GET/PUT/DELETE —
            // and putting a literal segment after a parameter segment is exactly
            // the shape that stops matching the moment someone adds a POST to it.
            Route::post('/roles/bulk-action', [RoleController::class, 'bulkAction'])->name('api.v1.roles.bulk-action')->middleware('throttle:bulk-action');
        });


        // Permission catalogue — read-only by design (P6-C7). Index only, on
        // purpose: the catalogue is defined in code, so a write endpoint would
        // be new behaviour rather than an API surface for existing behaviour.
        // The catalogue is defined in code (P6-C7), so switching `roles` off
        // does not invalidate it — which is why it is its own flag.
        Route::middleware('feature:permissions')->group(function () {
            Route::get('/permissions', [PermissionController::class, 'index'])->name('api.v1.permissions.index')->can('permissions.view');
        });

        // Feature flags — the management API, NOT the enforcement.
        //
        // Nothing here is gated on `feature:{slug}`: that middleware goes on
        // the routes of the modules being controlled, and putting it here would
        // mean the endpoints that re-enable a flag disappear with it, leaving
        // no way back. Same reasoning as the web group, and the reason the
        // feature middleware is absent from these three lines.
        //
        // Unlike the users and roles bulk routes, this one CAN carry a route
        // gate: every flag action is the same `features.manage` permission
        // (turning a module off is not a lesser privilege than turning it on),
        // so nothing is action-dependent here. `BulkFeatureRequest` repeats the
        // check for the same reason every other FormRequest authorizes its own
        // payload — the route is not the only caller a future refactor may add.
        // The read is `features.view`, the same split the web page uses.
        Route::get('/features', [FeatureController::class, 'index'])
            ->name('api.v1.features.index')->can('features.view');
        Route::post('/features/bulk-action', [FeatureController::class, 'bulkAction'])
            ->name('api.v1.features.bulk-action')->can('features.manage')->middleware('throttle:bulk-action');
        Route::post('/features/{feature}/toggle', [FeatureController::class, 'toggle'])->name('api.v1.features.toggle')->can('features.manage');
    });

    // ---------------------------------------------------------------------------
    // Password change — reachable even when password is expired/must-change.
    // Uses auth:sanctum only, NOT password.change.required, so an expired
    // user can always recover.
    // ---------------------------------------------------------------------------
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::post('/auth/password/change', ApiPasswordChangeController::class)->name('api.v1.auth.password.change');
    });
});
