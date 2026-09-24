<?php

namespace App\Actions\V1\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Revoke all active tokens and sessions for a user (logout everywhere).
 */
class LogoutAllDevicesAction
{
    /**
     * Delete all tokens and sessions for the user.
     *
     * @param  User  $user
     * @return array  ['success' => true]
     */
    public function run($user): array
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();

        $user->tokens()->delete();

        return ['success' => true];
    }
}
