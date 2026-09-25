<?php

namespace App\Jobs;

use App\Jobs\Concerns\AuditsSystemActivity;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\InactivityLock;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * InactivityLockSweep — daily sweep to lock inactive accounts.
 *
 * Evaluates users against InactivityLock::shouldLock() and locks
 * inactive accounts + revokes sessions/tokens.
 */
class InactivityLockSweep implements ShouldQueue
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
        $enabled = SystemSetting::getBool('inactivity_lock_enabled', true);
        $lockDays = SystemSetting::getInt('inactivity_lock_days', 30);

        Log::info('Inactivity lock sweep started', [
            'enabled' => $enabled,
            'lock_days' => $lockDays,
            'timezone' => config('app.timezone'),
        ]);

        if (! $enabled) {
            Log::info('Inactivity lock sweep skipped', ['reason' => 'disabled']);

            return;
        }

        $inactiveUsers = User::where('is_active', true)
            ->where('is_locked', false)
            ->get();

        $locked = 0;
        foreach ($inactiveUsers as $user) {
            if (InactivityLock::shouldLock($user)) {
                InactivityLock::lock($user);

                $locked++;

                $this->audit($user, 'auth.inactivity_lock.sweep', [
                    'last_activity_at' => $user->last_activity_at?->toIso8601String(),
                ]);

                Log::info('User locked due to inactivity', [
                    'user_id' => $user->id,
                    'last_activity_at' => $user->last_activity_at,
                ]);
            }
        }

        Log::info('Inactivity lock sweep completed', [
            'candidate_count' => $inactiveUsers->count(),
            'locked_count' => $locked,
            'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
        ]);
    }
}
