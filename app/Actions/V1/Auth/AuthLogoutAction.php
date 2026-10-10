<?php

namespace App\Actions\V1\Auth;

use App\Models\User;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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

        $guard = Auth::guard();

        // The database writes and the audit row are one transaction, so a
        // failure part way through cannot leave tokens deleted under an
        // `auth.logout` row that rolled back. The ordering is unchanged and still
        // matters: the row is written last, so it never claims a logout that did
        // not happen.
        //
        // Session invalidation stays outside. The session store is flushed when
        // the response is sent, not here, so there is no write of ours to
        // include — and a rollback could not put a regenerated session back.
        DB::transaction(function () use ($user, $guard): void {
            // Token-based API logout. A web session has no current access token.
            $user->currentAccessToken()?->delete();

            // Sanctum's guard is a RequestGuard and has no logout(); only a
            // stateful guard can end the session.
            if ($guard instanceof StatefulGuard) {
                $guard->logout();
            }

            $user->audit('auth.logout', $user);
        });

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }
}
