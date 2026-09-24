<?php

namespace App\Actions\V1\Auth;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Shared password-change logic, used by both Web and API controllers.
 *
 * Validates the current password, enforces the IM8 policy, checks password
 * history (if enabled), hashes the new password, updates expiration, clears
 * must_change_password, revokes active sessions/tokens, and records history.
 */
class ChangePasswordAction
{
    public function __construct(
        private readonly RecordPasswordHistoryAction $recordHistoryAction,
    ) {}

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

        // Password history check (only if enabled).
        if (SystemSetting::getBool('password_history_enabled', true) && $this->recentlyUsed($user, $newPassword)) {
            $count = SystemSetting::getInt('password_history_count', 5);

            throw ValidationException::withMessages([
                'password' => ["You cannot reuse one of your last {$count} passwords."],
            ]);
        }

        return DB::transaction(function () use ($user, $newPassword) {
            // 'hashed' cast on User model auto-hashes, assign plain password.
            $user->password = $newPassword;
            $user->must_change_password = false;
            $user->password_expires_at = $this->calculateExpiration();
            $user->save();

            // Record password history.
            $this->recordHistoryAction->run($user, $user->password);

            // Revoke all active Sanctum tokens for this user.
            $user->tokens()->delete();

            return true;
        });
    }

    /**
     * Calculate the next password expiration timestamp.
     */
    protected function calculateExpiration(): ?\Illuminate\Support\Carbon
    {
        $days = SystemSetting::getInt('password_expiration_days', 90);

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
        $count = SystemSetting::getInt('password_history_count', 5);

        $history = DB::table('password_histories')
            ->where('user_id', $user->id)
            ->orderBy('id', 'desc')
            ->limit($count)
            ->get();

        return $history->contains(fn($h) => Hash::check($newPassword, $h->password));
    }
}
