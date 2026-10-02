<?php

namespace App\Actions\V1\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Complete a successful login on either channel.
 *
 * ## Why this exists
 *
 * `AuthAuthenticateAction` verifies credentials and returns; no session exists
 * yet. `auth.login` therefore had no action to live in — the controller created
 * the session or the token, touched `last_activity_at`, and audited all three
 * inline, which made the successful path the only one whose audit and state
 * change could drift apart.
 *
 * ## Why one action serves both channels
 *
 * The two channels differ in how a session is established and not in anything
 * else: the web starts a session, the API issues a token. Splitting them into
 * two actions would duplicate the audit write and the `last_activity_at` touch
 * for a difference that is one `if`.
 *
 * @return string|null The plaintext token on the API channel, null on the web.
 */
class AuthLoginCompletedAction
{
    /**
     * @param  bool  $remember
     * @return string|null
     */
    public function run(User $user, bool $remember): ?string
    {
        $isApi = request()->is('api/*');

        // Session first, on the web channel: the row below should describe a
        // login that is already observable. This ordering is unchanged — only
        // the persisted writes around it were grouped.
        if (! $isApi) {
            Auth::login($user, $remember);
        }

        // Everything the platform persists is one transaction: the token on the
        // API channel, last_activity_at, and the audit row. As unguarded
        // statements a failure between them left a live token under an
        // `auth.login` row that never committed — or the inverse, a recorded
        // login whose token was never issued, which is the worse of the two
        // because the row is the only record a login happened.
        //
        // `Auth::login()` stays outside it deliberately. It is session state,
        // flushed when the response is sent rather than here, so there is no
        // write of ours to include — and a rollback could not un-login the user
        // anyway. The two channels never need both: the token path skips the
        // session entirely.
        return DB::transaction(function () use ($user, $remember, $isApi): ?string {
            $token = $isApi
                ? $user->createToken('auth-token')->plainTextToken
                : null;

            $user->updateQuietly(['last_activity_at' => now()]);

            $user->audit('auth.login', null, [
                'remember' => $remember,
            ]);

            return $token;
        });
    }
}
