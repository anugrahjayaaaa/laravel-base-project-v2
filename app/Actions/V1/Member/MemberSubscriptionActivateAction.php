<?php

namespace App\Actions\V1\Member;

use App\Models\Member;
use App\Models\MemberSubscription;
use App\Models\MemberTier;
use App\Models\RoleLookup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Issue a membership period for a member (Phase 18, P18-B3).
 *
 * The tier's role is copied onto the subscription row at activation and applied
 * to the `User` (never to the profile), so `can:` keeps working and a later tier
 * rename cannot make the expiry sweep revoke the wrong role.
 *
 * A retired tier (`is_active = false`) issues nothing new but takes nothing from
 * a period already running — the renewal rule in `membership.md` § Tiers.
 */
class MemberSubscriptionActivateAction
{
    public function run(Member $member, MemberTier $tier, ?User $causer = null): MemberSubscription
    {
        if (! $tier->is_active) {
            $this->refuse('member_tier_id', "Tier {$tier->name} is not active, so it cannot be subscribed to.");
        }

        // One live period per member — the shape the MySQL-only unique partial
        // index cannot express on SQLite, so it is enforced here (P18-B1, D-5).
        if ($member->subscriptions()->currentlyActive()->exists()) {
            $this->refuse('member_id', 'This member already holds an active subscription.');
        }

        return DB::transaction(function () use ($member, $tier, $causer): MemberSubscription {
            $startsAt = now();

            $subscription = $member->subscriptions()->create([
                'member_tier_id' => $tier->id,
                'status' => MemberSubscription::STATUS_ACTIVE,
                'starts_at' => $startsAt,
                'expires_at' => $startsAt->copy()->addDays($tier->duration_days),
                // Copied per row, so the sweep revokes what was actually applied,
                // not whatever the tier happens to name now.
                'role_name' => $tier->role_name,
            ]);

            $member->audit('member.subscribed', $causer, [
                'target_id' => $member->id,
                'member_number' => $member->member_number,
                'subscription_id' => $subscription->id,
                'tier_name' => $tier->name,
                'expires_at' => $subscription->expires_at->toDateString(),
            ]);

            $user = $member->user;

            if ($tier->role_name !== null && $user !== null) {
                $this->addRole($user, $tier->role_name);

                $member->audit('member.role_synced', $causer, [
                    'target_id' => $member->id,
                    'member_number' => $member->member_number,
                    'role_name' => $tier->role_name,
                    'change' => 'applied',
                ]);
            }

            return $subscription;
        });
    }

    /**
     * Add the tier role to the account without disturbing the roles it already
     * holds — `syncRoles` sets an exact set, so the target is the current roles
     * plus this one. A role name that does not exist on the guard is skipped
     * rather than thrown on, matching `RoleAssignAction`.
     */
    private function addRole(User $user, string $roleName): void
    {
        $target = array_values(array_unique([...$user->roles->pluck('name')->all(), $roleName]));

        $user->syncRoles(RoleLookup::findMany($target)->values()->all());
    }

    private function refuse(string $field, string $message): void
    {
        $validator = Validator::make([], []);
        $validator->errors()->add($field, $message);

        throw new ValidationException($validator);
    }
}
