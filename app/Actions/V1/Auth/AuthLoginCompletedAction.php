<?php

namespace App\Actions\V1\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

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

        $token = $isApi
            ? $user->createToken('auth-token')->plainTextToken
            : null;

        if (! $isApi) {
            Auth::login($user, $remember);
        }

        // Written after the session exists, so the row describes a login that
        // is actually observable. Written before, a failure here would leave a
        // row claiming a session that never happened.
        $user->updateQuietly(['last_activity_at' => now()]);

        $user->audit('auth.login', null, [
            'remember' => $remember,
        ]);

        return $token;
    }
}
