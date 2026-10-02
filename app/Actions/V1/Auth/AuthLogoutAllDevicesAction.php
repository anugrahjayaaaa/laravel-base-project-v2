<?php

namespace App\Actions\V1\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Revoke all active tokens and sessions for a user (logout everywhere).
 */
class AuthLogoutAllDevicesAction
{
    /**
     * Delete all tokens and sessions for the user.
     *
     * @param  User  $user
     * @return array  ['success' => true]
     */
    public function run($user): array
    {
        // One transaction across both tables. Sessions and tokens are separate
        // tables, so a failure deleting the second left a user whose web
        // sessions were gone but whose API tokens still worked — and the
        // `auth.logout_all` row, written outside this, claimed they were not.
        DB::transaction(function () use ($user): void {
            DB::table('sessions')->where('user_id', $user->id)->delete();

            $user->tokens()->delete();

            $user->audit('auth.logout_all');
        });

        return ['success' => true];
    }
}
