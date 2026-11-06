<?php

namespace App\Actions\V1\Member;

use App\Jobs\SendMemberExpiryNotificationJob;
use App\Models\SystemSetting;

/**
 * Queue the bulk expiry warning run (Phase 18, P18-C2).
 *
 * The request that triggers a broadcast returns before any message is sent:
 * this Action reads the configured alert window once and hands it to the job,
 * so neither the web button nor the API twin can each decide what "expiring"
 * means, and the recipient scan happens on a worker, not in the request.
 */
class MemberExpiryBroadcastAction
{
    public function run(): void
    {
        // The fallback is the value the seeder writes — same shape as every
        // other SystemSetting reader in the app; the stored setting wins.
        SendMemberExpiryNotificationJob::dispatch(
            SystemSetting::getInt('member_expiry_alert_window_days', 14)
        );
    }
}
