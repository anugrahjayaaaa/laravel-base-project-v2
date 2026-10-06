<?php

namespace App\Actions\V1\Notification;

use App\Models\NotificationAudience;
use App\Models\User;
use App\Notifications\AccountStateChangedNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Announce an account's state change to both of its audiences.
 *
 * ## Why this exists rather than a `notify()` at each call site
 *
 * The rule has TWO audiences for an account event — the account holder, and the
 * administrators who could have done it — and getting one right and the other
 * wrong is the failure. Written at each action, the second `notify()` is the one
 * that gets forgotten, and it is the one nobody notices missing: an admin locks
 * an account by mistake, the user never learns why they cannot log in, and
 * nobody who can fix it was told.
 *
 * One entry point means both audiences are resolved in one place, and an event
 * added later cannot ship with only one of them.
 *
 * ## Why it sits next to the state change rather than inside the transaction
 *
 * The notification must not be able to fail the state change. A rolled-back
 * lock that already mailed "your account has been locked" is worse than no mail:
 * the user acts on a fact that did not happen. So the dispatch happens after the
 * action returns, in its own try/catch, and a failure is logged rather than
 * raised — the account is still locked, which is what the admin asked for.
 *
 * The cost is the mirror image: a dispatch that fails is silently lost, and the
 * log line is the only trace. That is the right trade — an operator can read the
 * log, nobody can un-ring a bell.
 */
class NotificationAccountStateAction
{
    /**
     * Notify the account holder and the administrators.
     *
     * @param  string  $event  One of `NotificationAudience::ADMINISTRATIVE_EVENTS`.
     */
    public function run(User $subject, string $event, ?User $causer = null): void
    {
        $notification = new AccountStateChangedNotification($subject, $causer, $event);

        // Deduplicated ONCE, across both batches. An administrator who is also
        // the account holder — an admin who locks their own colleague and is in
        // the `users.lock` set — is in both lists, and sending twice is how a
        // notification stops being read.
        $recipients = Collection::make([$subject])
            ->merge(NotificationAudience::forEvent($event))
            // A CLOSURE, not `unique('getKey')`. Measured: `unique('getKey')` on a
            // collection of two distinct users returns ONE. `unique()` takes the
            // string as a data path and never calls the accessor, so every model's
            // value resolves the same way and all but one is dropped — which is
            // exactly what happened, and it silenced the administrative half of
            // the rule while leaving the tests' own resolver assertions green.
            ->unique(fn (User $user): int|string => $user->getKey())
            ->values();

        try {
            // Through the FACADE, not `$notification->send(...)`. The facade is
            // what `Notification::fake()` swaps, so an instance-level call would
            // bypass the fake entirely — and every test here would pass against a
            // transport that was never exercised.
            Notification::send($recipients, $notification);
        } catch (Throwable $e) {
            // The account is already in its new state by the time this runs, so a
            // failure here cannot and must not undo it. Logged, not raised: the
            // admin asked for a lock, and the lock is what happened.
            Log::error('Account state notification failed', [
                'event' => $event,
                'subject_id' => $subject->getKey(),
                'causer_id' => $causer?->getKey(),
                'recipients' => $recipients->count(),
                'exception' => $e->getMessage(),
            ]);
        }
    }

}
