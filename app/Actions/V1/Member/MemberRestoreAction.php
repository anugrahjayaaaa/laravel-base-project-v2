<?php

namespace App\Actions\V1\Member;

use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Pull a trashed member profile back out of the trash (Phase 18).
 *
 * Only the profile returns. Its subscriptions keep the status they were left
 * with — an expiry that passed while the row sat in the trash is not re-opened
 * here — and the login account was never touched by the delete, so there is
 * nothing to re-enable beside the loyalty record itself.
 */
class MemberRestoreAction
{
    public function run(Member $member, ?User $causer = null): Member
    {
        DB::transaction(function () use ($member, $causer): void {
            $member->restore();

            $member->audit('member.restored', $causer, [
                'target_id' => $member->id,
                'member_number' => $member->member_number,
            ]);
        });

        return $member;
    }
}
