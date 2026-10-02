<?php

namespace App\Jobs;

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
 * InactivityLockSweep — minute-configured sweep to lock inactive accounts.
 *
 * Evaluates users against InactivityLock::shouldLock() and locks
 * inactive accounts + revokes sessions/tokens.
 */
class InactivityLockSweep implements ShouldQueue
{
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

        $graceEnabled = SystemSetting::getBool('inactivity_lock_grace_enabled', true);
        $graceDays = $graceEnabled
            ? SystemSetting::getInt('inactivity_lock_grace_days', 30)
            : 0;
        $normalCutoff = now()->subDays($lockDays);
        $graceCutoff = now()->subDays($lockDays + $graceDays);

        $locked = 0;
        $candidateCount = 0;

        User::where('is_active', true)
            ->where('is_locked', false)
            ->where(function ($query) use ($normalCutoff, $graceCutoff): void {
                $query
                    ->where(function ($query) use ($normalCutoff): void {
                        $query->whereNotNull('last_activity_at')
                            ->where('last_activity_at', '<', $normalCutoff);
                    })
                    ->orWhere(function ($query) use ($graceCutoff): void {
                        $query->whereNull('last_activity_at')
                            ->where('created_at', '<', $graceCutoff);
                    });
            })
            ->chunkById(500, function ($users) use (&$locked, &$candidateCount): void {
                $candidateCount += $users->count();

                foreach ($users as $user) {
                    if (InactivityLock::shouldLock($user)) {
                        // The audit row comes back from the lock, inside its
                        // transaction. This loop used to write
                        // `auth.inactivity_lock.sweep` itself on the line after,
                        // outside — the same placement defect the middleware had.
                        InactivityLock::lock($user, 'auth.inactivity_lock.sweep', [
                            'causer' => 'SYSTEM',
                            'source' => 'system',
                        ]);

                        $locked++;
                    }
                }
            });

        Log::info('Inactivity lock sweep completed', [
            'candidate_count' => $candidateCount,
            'locked_count' => $locked,
            'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
        ]);
    }
}
