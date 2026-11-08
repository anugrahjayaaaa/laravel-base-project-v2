<?php

namespace App\Jobs;

use App\Actions\V1\Member\MemberExpirePointsAction;
use App\Jobs\Concerns\ReadsCorrelationId;
use App\Support\FeatureCatalog;
use App\Support\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Daily check of `member_points_expiry_date`.
 *
 * The date is the operator's, not this job's: empty means points never expire
 * and the run costs one settings read. Scheduled, not dispatched from a request
 * — a reset touches every member with a balance, so it belongs on the same
 * `sweeps` queue as the other idempotent scheduled work.
 */
class MemberPointsExpirySweepJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use ReadsCorrelationId;
    use SerializesModels;

    public function __construct()
    {
        $this->onQueue(QueueName::SWEEPS);
    }

    /** @var int */
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

    public function failed(?Throwable $exception): void
    {
        Log::error('Member points expiry sweep failed', [
            'attempts' => $this->tries,
            'request_id' => $this->requestId(),
            'exception' => $exception?->getMessage(),
        ]);
    }

    /**
     * A retry is safe: each chunk commits on its own, so a second run either
     * re-does nothing (the balances are already zero and get skipped) or
     * finishes the chunks the first run never reached.
     */
    public function handle(MemberExpirePointsAction $action): void
    {
        // Kill switch first. A stored expiry date outlives the flag being turned
        // off, and zeroing balances from a module the operator has disabled is
        // the opposite of what a switch is for.
        if (! FeatureCatalog::isActive('member_loyalty')) {
            Log::info('Member points expiry sweep skipped', [
                'reason' => 'feature_off',
                'request_id' => $this->requestId(),
            ]);

            return;
        }

        $reset = $action->run();

        if ($reset === 0) {
            return;
        }

        Log::info('Member points expiry sweep completed', [
            'reset_count' => $reset,
            'request_id' => $this->requestId(),
        ]);
    }
}
