<?php

namespace App\Actions\V1\Auth;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * RecordPasswordHistoryAction — store password hash + prune old entries beyond limit.
 *
 * Called by ChangePasswordAction, ResetPasswordAction, and CreateUserAction after
 * a successful password mutation. Reads password_history_count from
 * SystemSetting to determine retention.
 */
class RecordPasswordHistoryAction
{
    /**
     * Store the new hash and prune entries exceeding the configured limit.
     *
     * @param  User   $user            The user whose password changed.
     * @param  string $hashedPassword  The already-hashed new password.
     */
    public function run(User $user, string $hashedPassword): void
    {
        DB::table('password_histories')->insert([
            'user_id' => $user->id,
            'password' => $hashedPassword,
            'created_at' => now(),
        ]);

        $count = SystemSetting::getInt('password_history_count', 5);

        if ($count <= 0) {
            DB::table('password_histories')
                ->where('user_id', $user->id)
                ->delete();
            return;
        }

        // Keep newest $count entries, delete the rest.
        $keepIds = DB::table('password_histories')
            ->where('user_id', $user->id)
            ->orderBy('id', 'desc')
            ->limit($count)
            ->pluck('id');

        DB::table('password_histories')
            ->where('user_id', $user->id)
            ->whereNotIn('id', $keepIds)
            ->delete();
    }
}
