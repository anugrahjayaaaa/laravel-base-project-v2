<?php

namespace App\Actions\V1\Member;

use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Permanently delete a trashed member profile (Phase 18).
 *
 * Two-step on purpose: the lookup that reaches this Action is
 * `Member::onlyTrashed()`, so a live profile cannot be erased without being
 * trashed first — the same ordering the users module enforces, and the reason a
 * misclick on one button is never an unrecoverable loss.
 *
 * The database does the destruction. `member_subscriptions` and
 * `member_point_histories` are `cascadeOnDelete` on `members.id`, so a hard
 * delete takes the whole history with it and the audit row written here is the
 * only remaining record of what the balance was. The login account is NOT
 * touched: `members.user_id` points at `users`, and nothing deletes it — that
 * decision belongs to the users screen.
 */
class MemberForceDeleteAction
{
    public function run(Member $member, ?User $causer = null): void
    {
        DB::transaction(function () use ($member, $causer): void {
            // Captured before the delete because the cascade removes the rows and
            // a trashed-only read cannot see them afterwards: the counts are what
            // tell an auditor how much history one click destroyed.
            $destroyed = [
                'member_number' => $member->member_number,
                'subscriptions' => $member->subscriptions()->count(),
                'point_history_rows' => $member->pointHistories()->count(),
            ];

            $member->forceDelete();

            // No `target_id` — the row it named no longer exists, and an audit
            // property pointing at a dead id reads as a broken link rather than
            // as a deletion.
            $member->audit('member.force_deleted', $causer, $destroyed);
        });
    }
}
