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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PasswordExpirySweep — minute-configured sweep to flag expired passwords.
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

        $flagged = 0;
        $candidateCount = 0;

        User::where('is_active', true)
            ->where('is_locked', false)
            ->where('must_change_password', false)
            ->whereNotNull('password_expires_at')
            ->where('password_expires_at', '<', now())
            ->chunkById(500, function ($users) use (&$flagged, &$candidateCount): void {
                $candidateCount += $users->count();

                foreach ($users as $user) {
                    if (PasswordExpiry::isExpired($user)) {
                        // One transaction per user: the flag and its audit row
                        // are one fact. As two unguarded statements a failure
                        // between them left must_change_password set with no
                        // record of why, or a row claiming a flag that rolled
                        // back. The inactivity sweep had the same shape.
                        DB::transaction(function () use ($user): void {
                            $user->update(['must_change_password' => true]);

                            $this->audit($user, 'auth.password_expiry.sweep');
                        });

                        $flagged++;
                    }
                }
            });

        Log::info('Password expiry sweep completed', [
            'candidate_count' => $candidateCount,
            'flagged_count' => $flagged,
            'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
        ]);
    }
}
