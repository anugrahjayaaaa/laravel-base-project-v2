<?php

namespace App\Actions\V1\Member;

use App\Actions\V1\User\UserCreateAction;
use App\Models\Member;
use App\Models\User;
use App\Support\SystemRole;
use Illuminate\Support\Facades\DB;

/**
 * Create the account and its loyalty profile from one form (Phase 18).
 *
 * A member is a user (owner decision 2026-11-05), so this delegates the account
 * half to `UserCreateAction` rather than copying it: generated temporary
 * password, `must_change_password`, the welcome mail with the signed
 * verification link, the `user.created` audit row and the administrator
 * notification all behave exactly as they do on the users form. The role is
 * fixed to `member` — the operator does not choose it, and `PermissionSeeder`
 * grants that role nothing, so creating a member hands out no privilege.
 *
 * Two transactions, deliberately not one. `UserCreateAction` sends its mail
 * after ITS commit; wrapped in an outer transaction that mail would go out
 * inside it, and a rolled-back member profile would still have delivered a
 * working password and a signed verification link for an account that no longer
 * exists. What that costs is a narrow window where the account exists without
 * its profile — the member number is no longer operator input, so nothing the
 * form can send is consulted before that window: `Member::nextMemberNumber()`
 * reserves it inside this transaction.
 */
class MemberCreateAction
{
    public function __construct(
        private readonly UserCreateAction $createUserAction,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data  name, username, email, notes
     */
    public function run(array $data, ?User $causer = null): Member
    {
        $user = $this->createUserAction->run(
            [
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'],
                'roles' => [SystemRole::MEMBER],
            ],
            causer: $causer,
        );

        return DB::transaction(function () use ($data, $causer, $user): Member {
            $member = Member::create([
                'user_id' => $user->id,
                'member_number' => Member::nextMemberNumber(),
                'joined_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);

            $member->audit('member.created', $causer, [
                'target_id' => $member->id,
                'member_number' => $member->member_number,
                'target_email' => $user->email,
                'target_username' => $user->username,
            ]);

            return $member;
        });
    }
}
