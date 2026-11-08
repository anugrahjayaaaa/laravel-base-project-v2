<?php

namespace App\Actions\V1\Member;

use App\Jobs\SendMemberExpiryNotificationJob;
use App\Models\SystemSetting;
use App\Models\User;

/**
 * Queue the bulk expiry warning run (Phase 18, P18-C2).
 *
 * The request that triggers a broadcast returns before any message is sent:
 * this Action reads the configured alert window once and hands it to the job,
 * so neither the web button nor the API twin can each decide what "expiring"
 * means, and the recipient scan happens on a worker, not in the request.
 *
 * The trigger is audited. A mass mail is an operator decision with an audience
 * of everyone in the window, and before this row existed the trail recorded a
 * thousand messages going out without naming who told them to go.
 */
class MemberExpiryBroadcastAction
{
    public function run(User $causer): void
    {
        // The fallback is the value the seeder writes — same shape as every
        // other SystemSetting reader in the app; the stored setting wins.
        $window = SystemSetting::getInt('member_expiry_alert_window_days', 14);

        // Audited before the dispatch, never after: a run that went out with no
        // record of who ordered it is the hole this row closes, and the order is
        // what makes it impossible — if the write fails, nothing is queued.
        // No transaction around one insert, because there is nothing to roll
        // back next to it.
        $causer->audit('member.expiry_broadcasted', $causer, [
            'alert_window_days' => $window,
        ]);

        SendMemberExpiryNotificationJob::dispatch($window);
    }
}
