<?php

namespace App\Actions\V1\Member;

use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Move a member profile to the trash (Phase 18, P18-B6).
 *
 * Only the loyalty profile is trashed. The login account it hangs off stays
 * alive and is deleted on its own screen (`UserDeleteAction`), because a member
 * can be removed from the programme while nobody has decided their account
 * should stop being able to sign in.
 *
 * The audit row is written inside the transaction, by this layer, so every
 * caller produces exactly one `member.deleted` record and a rollback takes it
 * with it.
 */
class MemberDeleteAction
{
    public function run(Member $member, ?User $causer = null): void
    {
        // The `{member}` binding resolves withTrashed, so this is reachable for a
        // row already in the trash. Deleting twice would write a second
        // `member.deleted` trail entry for an event that did not happen.
        if ($member->trashed()) {
            return;
        }

        DB::transaction(function () use ($member, $causer): void {
            // Captured before the delete: a trashed row is only readable from the
            // trashed scope, so the number is what an operator scanning the audit
            // list still needs to recognise the subject.
            $identity = ['target_id' => $member->id, 'member_number' => $member->member_number];

            $member->delete();

            $member->audit('member.deleted', $causer, $identity);
        });
    }
}
