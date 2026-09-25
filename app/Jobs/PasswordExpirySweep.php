<?php

namespace App\Jobs;

use App\Jobs\Concerns\AuditsSystemActivity;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\PasswordExpiry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * PasswordExpirySweep — daily sweep to flag expired passwords.
 *
 * Evaluates users against PasswordExpiry::isExpired() and sets
 * must_change_password = true for expired accounts.
 */
class PasswordExpirySweep implements ShouldQueue
{
    use AuditsSystemActivity;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $startedAt = microtime(true);
        $enabled = SystemSetting::getBool('password_expiry_enabled', true);
        $expiryDays = SystemSetting::getInt('password_expiry_days', 90);

        Log::info('Password expiry sweep started', [
            'enabled' => $enabled,
            'expiry_days' => $expiryDays,
            'timezone' => config('app.timezone'),
        ]);

        if (! $enabled) {
            Log::info('Password expiry sweep skipped', ['reason' => 'disabled']);

            return;
        }

        $expiredUsers = User::where('is_active', true)
            ->where('is_locked', false)
            ->where('must_change_password', false)
            ->whereNotNull('password_expires_at')
            ->where('password_expires_at', '<', now())
            ->get();

        $flagged = 0;
        foreach ($expiredUsers as $user) {
            if (PasswordExpiry::isExpired($user)) {
                $user->update(['must_change_password' => true]);

                $flagged++;

                $this->audit($user, 'auth.password_expiry.sweep');

                Log::info('Password expired — must_change_password set', [
                    'user_id' => $user->id,
                ]);
            }
        }

        Log::info('Password expiry sweep completed', [
            'candidate_count' => $expiredUsers->count(),
            'flagged_count' => $flagged,
            'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
        ]);
    }
}
