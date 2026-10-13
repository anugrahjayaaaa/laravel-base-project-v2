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
use Throwable;

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
     * Queue conventions (Phase 11 MON-P11-05, raised as Phase 10 §11 P10-E14).
     *
     * Three attempts, and `$timeout` BELOW `REDIS_QUEUE_RETRY_AFTER` (180 on this
     * install) — see `InactivityLockSweep` for both reasons and for why a timeout
     * above the retry window lets a job run twice at once.
     *
     * The retry is safe because the flag is already set: a second pass re-reads
     * `PasswordExpiry::isExpired()` and finds the work done, so a partial sweep
     * resumes rather than restarting.
     *
     * @var int
     */
    public $tries = 3;

    /** @var int */
    public $timeout = 150;

    /** @var int */
    public $maxExceptions = 3;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    /**
     * The final attempt is logged.
     *
     * `failed()` fires only after the last try, so without this line the operator
     * looking at a failed sweep has a trace from the third attempt and no way to
     * see that the first two died on something else.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Password expiry sweep failed', [
            'attempts' => $this->tries,
            'exception' => $exception?->getMessage(),
        ]);
    }

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
