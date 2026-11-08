<?php

namespace App\Observers;

use App\Actions\V1\Member\MemberAnalyticsStatsAction;
use App\Models\Member;
use App\Models\MemberSubscription;

/**
 * Busts the cached membership analytics whenever a row the figures are computed
 * from moves — a member joining, a period starting, expiring or being cancelled,
 * a subscription changing tier.
 *
 * Watching the models rather than calling the bust from each of the eighteen
 * member Actions is deliberate: the bulk handlers and the expiry sweep jobs write
 * through those same Actions, and one missed call site is a dashboard that reports
 * last week's growth forever.
 *
 * Sign-ins are the exception: `AuthLoginCompletedAction` updates
 * `users.last_activity_at` with `updateQuietly()`, which skips observers, so the
 * DAU/MAU figures age out through the Action's TTL instead.
 *
 * @see \App\Observers\UserObserver for why the bust is deferred to afterCommit
 */
class MemberAnalyticsObserver
{
    public function saved(Member|MemberSubscription $row): void
    {
        MemberAnalyticsStatsAction::bustCache();
    }

    public function deleted(Member|MemberSubscription $row): void
    {
        MemberAnalyticsStatsAction::bustCache();
    }

    public function restored(Member|MemberSubscription $row): void
    {
        MemberAnalyticsStatsAction::bustCache();
    }
}
