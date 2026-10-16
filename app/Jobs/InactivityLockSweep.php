<?php

namespace App\Jobs;

use App\Jobs\Concerns\ReadsCorrelationId;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\InactivityLock;
use App\Support\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

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
    use ReadsCorrelationId;
    use SerializesModels;

    /**
     * Named queue, not `default` (Phase 11 P11-E1) — set on the instance, NOT
     * as a `public $queue` property: `Queueable` already declares it and PHP
     * refuses the redeclaration (a class-load fatal `php -l` misses; see
     * `GenerateAuditExport`, which documents the rule). Sweeps are last in
     * the worker's `--queue` order: scheduled, idempotent, never urgent.
     */
    public function __construct()
    {
        $this->onQueue(QueueName::SWEEPS);
    }

    /**
     * Queue conventions (Phase 11 MON-P11-05, raised as Phase 10 §11 P10-E14).
     *
     * Three attempts rather than one: a sweep that dies on a lock timeout skips
     * a whole day's worth of locks until the next scheduled run, and the work it
     * already did is idempotent — `InactivityLock::shouldLock()` re-reads state,
     * so a retry re-evaluates rather than double-locking.
     *
     * `$timeout` sits BELOW `REDIS_QUEUE_RETRY_AFTER` (180 on this install) for
     * the reason `GenerateAuditExport` documents: a timeout above the retry
     * window lets the job be released and run twice at the same time, and this
     * one writes locks.
     *
     * @var int
     */
    public $tries = 3;

    /** @var int */
    public $timeout = 150;

    /** @var int */
    public $maxExceptions = 3;

    /**
     * Long enough for the failing attempt to clear.
     *
     * A sweep runs on a schedule, so a retry that lands in the same minute as
     * the next one is wasted work. 60 then 300 spreads the three attempts across
     * five minutes, which covers the lock-timeout case this is here for.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    /**
     * The final attempt is logged.
     *
     * `failed()` fires only after the last try, so `failed_jobs` holds one row
     * per job and overwrites its payload on each retry — without this line the
     * operator looking at a failed sweep has a trace from the third attempt and
     * no way to see that the first two died on something else.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Inactivity lock sweep failed', [
            'attempts' => $this->tries,
            'request_id' => $this->requestId(),
            'exception' => $exception?->getMessage(),
        ]);
    }

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
