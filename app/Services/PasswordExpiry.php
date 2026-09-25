<?php

namespace App\Services;

use App\Models\SystemSetting;
use App\Models\User;

/**
 * PasswordExpiry — pure logic for password expiration evaluation.
 *
 * Reads settings from SystemSetting (admin-editable via /settings).
 * All methods are testable without HTTP context.
 */
class PasswordExpiry
{
    /**
     * Determine if the user's password has expired.
     */
    public static function isExpired(User $user): bool
    {
        if (! SystemSetting::getBool('password_expiry_enabled', true)) {
            return false;
        }

        return $user->password_expires_at
            && $user->password_expires_at->isPast();
    }

    /**
     * Calculate days remaining until password expiry.
     *
     * @return int  Days until expiry (0 if expired or no expiry set)
     */
    public static function daysUntilExpiry(User $user): int
    {
        if (! $user->password_expires_at) {
            return 0;
        }

        $now = now();
        if ($user->password_expires_at->isPast()) {
            return 0;
        }

        return (int) $now->diffInDays($user->password_expires_at, false);
    }

    /**
     * Determine if the user should see an expiry warning banner.
     */
    public static function shouldWarn(User $user): bool
    {
        if (! SystemSetting::getBool('password_expiry_enabled', true) || ! $user->password_expires_at || $user->password_expires_at->isPast()) {
            return false;
        }

        $warnDays = SystemSetting::getInt('password_expiry_warn_days', 14);
        $daysUntil = self::daysUntilExpiry($user);

        return $daysUntil > 0 && $daysUntil <= $warnDays;
    }
}
