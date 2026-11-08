<?php

namespace App\Jobs;

use App\Notifications\MemberExpiryWarningNotification;
use App\Models\Member;
use App\Models\MemberSubscription;
use App\Jobs\Concerns\ReadsCorrelationId;
use App\Support\FeatureCatalog;
use App\Support\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Bulk "your membership is expiring" mail run (Phase 18, P18-C2).
 *
 * One aggregate query collapses a member's live periods to the earliest expiry,
 * so a member with two stacked subscriptions is mailed once — the brief's "not
 * one message per subscription". Delivery never happens in this process:
 * `MemberExpiryWarningNotification` is `ShouldQueue`, so 1 000 recipients are
 * 1 000 small queued jobs the workers drip through, not 1 000 SMTP connections
 * opened back to back.
 *
 * The window is passed in at dispatch time rather than re-read here: the
 * operator pressed the button against the window on their screen, and changing
 * the setting minutes later must not silently change who they chose to warn.
 */
class SendMemberExpiryNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use ReadsCorrelationId;
    use SerializesModels;

    public function __construct(public readonly int $alertWindowDays)
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
        Log::error('Member expiry notification run failed', [
            'attempts' => $this->tries,
            'request_id' => $this->requestId(),
            'exception' => $exception?->getMessage(),
        ]);
    }

    public function handle(): void
    {
        if (! FeatureCatalog::isActive('member_loyalty')) {
            Log::info('Member expiry notification run skipped', [
                'reason' => 'feature_off',
                'request_id' => $this->requestId(),
            ]);

            return;
        }

        $windowEnd = Carbon::now()->addDays($this->alertWindowDays);

        $expiring = MemberSubscription::query()
            ->currentlyActive()
            ->where('expires_at', '<=', $windowEnd)
            ->selectRaw('member_id, MIN(expires_at) as expires_at')
            ->groupBy('member_id')
            ->get();

        if ($expiring->isEmpty()) {
            Log::info('Member expiry notification run found no recipients', [
                'request_id' => $this->requestId(),
            ]);

            return;
        }

        // Preload the accounts in 500-id pages so the run costs ~N/500 queries,
        // not N; each `notify()` call only enqueues (the notification is queued).
        $expiring->chunk(500)->each(function ($page): void {
            $members = Member::with('user')->whereIn('id', $page->pluck('member_id'))->get()->keyBy('id');

            foreach ($page as $row) {
                $member = $members->get($row->member_id);

                if ($member === null || $member->user === null) {
                    continue;
                }

                Notification::send(
                    $member->user,
                    new MemberExpiryWarningNotification($member, Carbon::parse($row->expires_at))
                );
            }
        });

        Log::info('Member expiry notification run dispatched', [
            'recipient_count' => $expiring->count(),
            'window_end' => $windowEnd->toDateTimeString(),
            'request_id' => $this->requestId(),
        ]);
    }
}
