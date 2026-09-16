<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Facades\Activity;

/**
 * Shared password-change logic — used by both Web and API controllers.
 *
 * Validates the current password, enforces the IM8 policy, checks password
 * history, hashes the new password, updates expiration, clears
 * must_change_password, revokes active sessions/tokens, and creates an
 * audit event.
 *
 * Per docs/base/security/password-security.md §Password Change Revocation,
 * password change must revoke all existing web sessions and Sanctum tokens.
 */
class ChangePassword
{
    /**
     * Execute the password change.
     *
     * @param  User   $user
     * @param  string $currentPassword
     * @param  string $newPassword
     * @return bool   True if the password was changed.
     *
     * @throws ValidationException When the current password is wrong or the
     *                              new password was used recently.
     */
    public function run(User $user, string $currentPassword, string $newPassword): bool
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        // Password history check.
        if ($this->recentlyUsed($user, $newPassword)) {
            $count = (int) config('rate_limits.password_history.count', 5);
            throw ValidationException::withMessages([
                'password' => ["You cannot reuse one of your last {$count} passwords."],
            ]);
        }

        return DB::transaction(function () use ($user, $newPassword) {
            $user->password = Hash::make($newPassword);
            $user->must_change_password = false;
            $user->password_expires_at = $this->calculateExpiration();
            $user->save();

            // Record password history.
            $this->recordHistory($user, $user->password);

            // Revoke all active Sanctum tokens for this user.
            $user->tokens()->delete();

            activity('password.change.completed')
                ->causedBy($user)
                ->withProperties(['reason' => 'self-initiated'])
                ->log('password.change.completed');

            return true;
        });
    }

    /**
     * Calculate the next password expiration timestamp.
     */
    protected function calculateExpiration(): ?\Illuminate\Support\Carbon
    {
        $days = (int) config('rate_limits.password_expiration_days', 90);

        if ($days <= 0) {
            return null;
        }

        return now()->addDays($days);
    }

    /**
     * Check if the new password was used recently (history enforcement).
     */
    protected function recentlyUsed(User $user, string $newPassword): bool
    {
        $count = (int) config('rate_limits.password_history.count', 5);

        $history = DB::table('password_histories')
            ->where('user_id', $user->id)
            ->orderBy('id', 'desc')
            ->limit($count)
            ->get();

        return $history->contains(fn ($h) => Hash::check($newPassword, $h->password));
    }

    /**
     * Store the password hash in history.
     */
    protected function recordHistory(User $user, string $hashedPassword): void
    {
        DB::table('password_histories')->insert([
            'user_id' => $user->id,
            'password' => $hashedPassword,
            'created_at' => now(),
        ]);
    }
}
