<?php

namespace App\Jobs;

use App\Actions\V1\Member\MemberSubscriptionExpireAction;
use App\Jobs\Concerns\ReadsCorrelationId;
use App\Models\MemberSubscription;
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
 * Daily sweep of subscriptions whose window has passed (Phase 18, P18-C1).
 *
 * Idempotent by construction: the row leaves the candidate set the moment the
 * Action flips its status, so a second run claims zero rows and a retry after
 * a partial failure resumes where the chunk stopped. The per-row mutation —
 * status, role revocation, audit — belongs to `MemberSubscriptionExpireAction`,
 * not here; this class owns only the scan and the kill switch.
 */
class MemberSubscriptionExpirySweepJob implements ShouldQueue
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
        Log::error('Member subscription expiry sweep failed', [
            'attempts' => $this->tries,
            'request_id' => $this->requestId(),
            'exception' => $exception?->getMessage(),
        ]);
    }

    public function handle(MemberSubscriptionExpireAction $action): void
    {
        // Kill switch first, same shape as the points sweep: rows stay expired
        // only as far as the sweep got, and a disabled module must not keep
        // mutating member state.
        if (! FeatureCatalog::isActive('member_loyalty')) {
            Log::info('Member subscription expiry sweep skipped', [
                'reason' => 'feature_off',
                'request_id' => $this->requestId(),
            ]);

            return;
        }

        $expired = 0;

        // `paused_at` is excluded exactly like `currentlyActive()` does: a
        // deactivated member froze their period, and freezing a clock the
        // operator stopped would expire the wrong row.
        MemberSubscription::query()
            ->where('status', MemberSubscription::STATUS_ACTIVE)
            ->whereNull('paused_at')
            ->where('expires_at', '<=', now())
            ->chunkById(500, function ($subscriptions) use ($action, &$expired): void {
                foreach ($subscriptions as $subscription) {
                    $action->run($subscription);
                    $expired++;
                }
            });

        if ($expired === 0) {
            return;
        }

        Log::info('Member subscription expiry sweep completed', [
            'expired_count' => $expired,
            'request_id' => $this->requestId(),
        ]);
    }
}
