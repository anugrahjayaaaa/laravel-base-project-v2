<?php

use App\Http\Controllers\Web\V1\Auth\AuthController;
use App\Http\Controllers\Web\V1\DashboardController;
use App\Http\Controllers\Web\V1\FeatureController;
use App\Http\Controllers\Web\V1\NotificationController;
use App\Http\Controllers\Web\V1\NotificationInboxController;
use App\Http\Controllers\Web\V1\PermissionController;
use App\Http\Controllers\Web\V1\ProfileController;
use App\Http\Controllers\Web\V1\RoleController;
use App\Http\Controllers\Web\V1\SystemSettingController;
use App\Http\Controllers\Web\V1\UserStateController;
use App\Http\Controllers\Web\V1\UserController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------------------
// Public routes — no authentication required
// ---------------------------------------------------------------------------
Route::get('/', function () {
    return view('pages.welcome', [
        'title' => config('app.name', 'Laravel Base Project'),
        'laravelVersion' => app()->version(),
    ]);
});

// ---------------------------------------------------------------------------
// Auth routes — login, password reset, email verification
// ---------------------------------------------------------------------------
Route::controller(AuthController::class)->group(function () {
    Route::get('/login', 'showLogin')->name('login');
    Route::post('/login', 'login')
        ->name('login.submit')
        ->middleware('throttle:login');

    Route::get('/forgot-password', 'showForgotPassword')->name('password.request');
    Route::post('/forgot-password', 'sendPasswordResetLink')
        ->name('password.email')
        ->middleware('throttle:forgot-password');

    Route::get('/reset-password', 'showResetPassword')->name('password.reset');
    Route::post('/reset-password', 'resetUserPassword')
        ->name('password.update')
        ->middleware('throttle:reset-password');

    // Both verbs check `registration_enabled` in the controller — a disabled
    // feature should 404, not 403, so no `can:` gate here.
    Route::get('/register', 'showRegister')->name('register');
    Route::post('/register', 'register')
        ->name('register.submit')
        ->middleware('throttle:register');

    Route::get('/verify-email', 'showVerifyEmail')->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', 'verifyEmail')->name('verification.verify');
    Route::post('/email/resend', 'resendVerification')
        ->name('verification.resend')
        ->middleware('throttle:resend-verification');
});

// ---------------------------------------------------------------------------
// Authenticated + verified + valid account state
// ---------------------------------------------------------------------------
Route::middleware(['auth:web,sanctum', 'verified', 'password.change.required', 'account.state'])->group(function () {

    // Auth management
    Route::controller(AuthController::class)->group(function () {
        Route::post('/logout', 'logout')->name('logout');
        // The two session routes are flagged but `logout` is not: logging out
        // must keep working when the module is switched off, or a bad flag
        // leaves an admin unable to end a session.
        Route::middleware('feature:sessions')->group(function () {
            Route::get('/sessions', 'showSessions')->name('sessions');
            Route::post('/sessions/logout-all', 'logoutAllDevices')->name('sessions.logout-all');
        });
    });

    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::middleware('feature:settings')->group(function () {
        Route::get('/settings', [SystemSettingController::class, 'index'])->name('settings.index')->can('settings.view');
        Route::post('/settings', [SystemSettingController::class, 'update'])->name('settings.update')->can('settings.manage');
    });

    // Profile
    Route::controller(ProfileController::class)->group(function () {
        Route::get('/profile', 'show')->name('profile.show');
        Route::put('/profile', 'update')->name('profile.update');
        Route::get('/password/expired', 'showExpiredPassword')->name('password.expired');
        Route::get('/password/change', 'show')->name('password.change');
        Route::put('/password/change', 'changePassword')->name('password.change.update');
    });

    // Users — flag `users` (P7-D7).
    //
    // One group, not a per-route call: a flag applied to 3 of 9 routes is a
    // partial gate, and the routes missed still work. The group is the unit, so
    // a route added inside it inherits the gate by construction.
    Route::middleware('feature:users')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index')->can('users.view');
        Route::get('/users/create', [UserController::class, 'create'])->name('users.create')->can('users.create');
        Route::post('/users', [UserController::class, 'store'])->name('users.store')->can('users.create');
        Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show')->can('users.view');
        Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit')->can('users.update');
        // PUT + PATCH in ONE route object: two routes sharing a name breaks
        // route:cache ("Another route has already been assigned name").
        Route::match(['put', 'patch'], '/users/{user}', [UserController::class, 'update'])->name('users.update')->can('users.update');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy')->can('users.delete');
        // restore/force-delete took a plain Request and UserRestoreAction checks
        // nothing, so these two wrote to any user's row by id with no gate at all.
        Route::post('/users/{user}/restore', [UserController::class, 'restore'])->name('users.restore')->can('users.restore');
        Route::delete('/users/{user}/force', [UserController::class, 'forceDelete'])->name('users.force-delete')->can('users.force_delete');

        Route::bind('user', function ($id) {
            return User::withTrashed()->findOrFail($id);
        });
        Route::post('/users/{user}/resend-verification', [UserController::class, 'resendVerification'])->name('users.resend-verification')->middleware('throttle:resend-verification')->can('users.update');
        // Self-service, so NOT gated on a permission — EmailChangeRequest authorizes
        // "your own account, or someone holding users.update", which is the rule the
        // profile page actually needs. cancel-email-change has no request class and
        // no guard, so it gets the same check inline rather than a permission gate
        // that would break the profile page.
        Route::post('/users/{user}/request-email-change', [UserController::class, 'requestEmailChange'])->name('users.request-email-change');
        Route::post('/users/{user}/cancel-email-change', [UserController::class, 'cancelEmailChange'])->name('users.cancel-email-change');
        Route::get('/email/verify-change/{user}', [UserController::class, 'verifyEmailChange'])
        ->name('email.verify-change')->middleware(['signed', 'throttle:email-verification']);
        // Deliberately NOT route-gated. The permission depends on which action is
        // requested — a bulk delete needs users.delete while a bulk activate needs
        // users.activate — so AuthorizesBulkAction maps action -> permission. Any
        // single `can:` here would be wrong in both directions: too permissive for
        // the dangerous actions, and it would 403 the harmless ones for callers who
        // legitimately hold only the matching permission.
        Route::post('/users/bulk-action', [UserController::class, 'bulkAction'])->name('users.bulk-action')->middleware('throttle:bulk-action');

        // User state toggles (Activate / Deactivate / Lock / Unlock)
        //
        // These four had NO authorization anywhere: UserStateController takes a plain
        // User and runs the action, and none of the four actions check anything, so
        // the route was the only defence — and it had no `can:`. Any authenticated
        // account could lock, unlock, activate or deactivate any other account,
        // including a superadmin. The per-row buttons are hidden by @can in the
        // view, which is not a control: a POST to the URL was all it took.
        Route::middleware(['throttle:user-state-actions'])->group(function () {
            Route::post('/users/{user}/activate', [UserStateController::class, 'activate'])->name('users.activate')->can('users.activate');
            Route::post('/users/{user}/deactivate', [UserStateController::class, 'deactivate'])->name('users.deactivate')->can('users.deactivate');
            Route::post('/users/{user}/lock', [UserStateController::class, 'lock'])->name('users.lock')->can('users.lock');
            Route::post('/users/{user}/unlock', [UserStateController::class, 'unlock'])->name('users.unlock')->can('users.unlock');
        });
    });

    // -----------------------------------------------------------------------
    // Roles & permissions — Phase 6.
    //
    // Group A added the read routes only, and pointed the create/edit forms at
    // roles.index as a placeholder because no save route existed yet.
    //
    // Gated as of Group C2: the catalogue these gates check against is seeded in
    // Group B (P6-B1), so the original reason to defer them is gone. The write
    // routes were never unguarded — their Form Requests authorized on the same
    // roles.* permissions — and now the reads are closed the same way.
    // -----------------------------------------------------------------------
    // Roles and permissions — flags `roles` and `permissions` (P7-D7). Two
    // groups, not one `feature:roles,permissions`: ANDing them would switch off
    // the permission catalogue whenever roles are off, and each is a separate
    // switch on the management page.
    Route::middleware('feature:roles')->group(function () {
        Route::post('/roles/bulk-action', [RoleController::class, 'bulkAction'])->name('roles.bulk-action')->middleware('throttle:bulk-action');
        Route::get('/roles', [RoleController::class, 'index'])->name('roles.index')->can('roles.view');
        Route::get('/roles/create', [RoleController::class, 'create'])->name('roles.create')->can('roles.create');
        // Explicit now as well as in StoreRoleRequest. Two checks of the same thing
        // is not defence in depth, it is a route table you can read: `route:list`
        // shows the gate, and the FormRequest still holds it if a route is ever
        // registered without one.
        Route::post('/roles', [RoleController::class, 'store'])->name('roles.store')->can('roles.create');
        Route::get('/roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit')->can('roles.update');
        Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update')->can('roles.update');
        Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy')->can('roles.delete');
        // Both take the raw id, NOT an implicit {role} binding: that binding resolves
        // through the SoftDeletes global scope, so it 404s every trashed role —
        // exactly the rows these two routes exist for. The controller looks the row
        // up with onlyTrashed() instead. POST, not GET, for both: they mutate.
        Route::post('/roles/{role}/restore', [RoleController::class, 'restore'])->name('roles.restore')->can('roles.restore');
        Route::delete('/roles/{role}/force', [RoleController::class, 'forceDelete'])->name('roles.force-delete')->can('roles.force_delete');
    });

    // The catalogue is defined in code (P6-C7), so switching `roles` off does
    // not invalidate it — which is exactly why it is its own flag.
    Route::middleware('feature:permissions')->group(function () {

        Route::get('/permissions', [PermissionController::class, 'index'])->name('permissions.index')->can('permissions.view');
    });

    // ---------------------------------------------------------------------------
    // Feature flags — Phase 7.
    //
    // The management page, NOT the enforcement. Nothing here is gated on
    // `feature:{slug}`: that middleware (P7-C1/C2) goes on the routes of the
    // modules being controlled, and putting it here would mean the page that
    // re-enables a flag disappears with it — leaving no way back.
    //
    // `enabled` rides as a query param because the confirm modal posts a form
    // to a fixed URL (confirmation-modal.js sets form.action, nothing else).
    // That is why the switch in pages/features/index is a trigger and not an
    // auto-submitting input.
    // ---------------------------------------------------------------------------
    Route::get('/features', [FeatureController::class, 'index'])->name('features.index')->can('features.view');
    // Not `->can()` here: the permission depends on which action is requested,
    // so BulkFeatureRequest decides. See routes/web.php users.bulk-action and
    // the class for why one features.manage gate covers both directions.
    Route::post('/features/bulk-action', [FeatureController::class, 'bulkAction'])
        ->name('features.bulk-action')->middleware('throttle:bulk-action');
    Route::post('/features/{feature}/toggle', [FeatureController::class, 'toggle'])->name('features.toggle')->can('features.manage');

    // -----------------------------------------------------------------------
    // Notifications & Mail — Phase 9 Groups A and B.
    //
    // Read routes render; the write routes persist. Every one of them carries
    // the module gate, and the write routes carry their own permission on top:
    // `notifications.manage` configures the transport, `notifications.send_test`
    // may mail an address a user typed. Sending to an arbitrary address is an
    // abuse vector, not a subset of configuring a transport.
    //
    // `password.change.required` sits ABOVE in the stack: a user with an expired
    // password cannot reconfigure the transport that would mail them the
    // reminder, which is the intended order — fix your credential before it is
    // used.
    // -----------------------------------------------------------------------
    Route::middleware('feature:notifications')->group(function () {
        Route::controller(NotificationController::class)->group(function () {
            Route::get('/notifications', 'index')->name('notifications.index')->can('notifications.view');
            Route::get('/notifications/channels', 'channels')->name('notifications.channels')->can('notifications.view');

            Route::post('/notifications', 'update')->name('notifications.update')->can('notifications.manage');
            Route::post('/notifications/channels', 'updateChannels')
                ->name('notifications.channels.update')->can('notifications.manage');
            Route::post('/notifications/test-mail', 'sendTestMail')
                ->name('notifications.test-mail')->can('notifications.send_test');
        });
    });

    // -----------------------------------------------------------------------
    // The user's own notification inbox — Phase 9 Group C (P9-C5/D5).
    //
    // `feature:notifications` and the auth stack, and NOTHING else. No
    // `notifications.view`: this page reads the viewer's OWN rows, so there is
    // no permission to hold — a user cannot reach another user's notifications
    // through it, which is why `NotificationInboxAction` scopes every query to
    // the relation and never accepts a bare id (D-2).
    //
    // It is behind the flag because it is part of the module and must vanish
    // with it. It is NOT behind a permission because the inbox is exactly what
    // most users would have no permission to reach.
    //
    // Separate controller, separate block: the admin pages and the inbox have
    // different gates, and merging them would mean one of the two gates is
    // applied to both.
    // -----------------------------------------------------------------------
    Route::middleware('feature:notifications')->group(function () {
        Route::controller(NotificationInboxController::class)->group(function () {
            Route::get('/notifications/inbox', 'index')->name('notifications.inbox');
            Route::post('/notifications/inbox/{id}/read', 'markAsRead')
                ->name('notifications.inbox.read');
            Route::post('/notifications/inbox/read-all', 'markAllAsRead')
                ->name('notifications.inbox.read-all');
        });
    });
});
