<?php

namespace App\Services;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * InactivityLock — pure logic for inactivity-based account locking.
 *
 * Reads settings from SystemSetting (admin-editable via /settings).
 * Locking sets is_locked = true and revokes all sessions/tokens.
 */
class InactivityLock
{
    /**
     * Determine if the user has been inactive beyond the configured threshold.
     */
    public static function isInactive(User $user): bool
    {
        if (! SystemSetting::getBool('inactivity_lock_enabled', true)) {
            return false;
        }

        if (! $user->last_activity_at) {
            return false;
        }

        $lockDays = SystemSetting::getInt('inactivity_lock_days', 30);

        return $user->last_activity_at->copy()->addDays($lockDays)->isPast();
    }

    /**
     * Determine if the user should be locked (inactive + not already locked).
     */
    public static function shouldLock(User $user): bool
    {
        return self::isInactive($user) && ! $user->is_locked;
    }

    /**
     * Lock the user account and revoke all sessions/tokens.
     */
    public static function lock(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user->update(['is_locked' => true]);

            // Revoke all active web sessions
            DB::table('sessions')
                ->where('user_id', $user->id)
                ->delete();

            // Revoke all Sanctum API tokens
            $user->tokens()->delete();
        });
    }
}
