<?php

namespace App\Actions\V1\Auth;

use App\Models\User;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
     * End a session and record it.
 *
 * ## Why the logout goes through the guard, not the session
 *
 * The two channels end a session in different places. The web session ends with
 * `Auth::logout()` plus an invalidated CSRF session. The API has no session at
 * all — its session IS a Sanctum token, and Sanctum registers a `RequestGuard`,
 * which has no `logout()` method. Calling it there throws, so the guard is
 * checked instead of assumed.
 *
 * ## Ordering
 *
 * The audit row records who ended a session, so it is written after the session
 * is gone and the user is logged out. Written first, a failure in the teardown
 * would leave a row claiming a logout that never completed — and the user is
 * already unauthenticated by then, so `$user` must be captured before.
 */
class AuthLogoutAction
{
    public function run(Request $request): void
    {
        $user = $request->user();

        if ($user === null) {
            return;
        }

        // Token-based API logout. A web session has no current access token.
        $user->currentAccessToken()?->delete();

        // Sanctum's guard is a RequestGuard and has no logout(); only a stateful
        // guard can end the session.
        $guard = Auth::guard();

        if ($guard instanceof StatefulGuard) {
            $guard->logout();
        }

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $user->audit('auth.logout');
    }
}
