<?php

namespace App\Actions\V1\Member\Concerns;

use App\Models\Member;
use App\Models\RoleLookup;
use App\Models\User;

/**
 * The per-row role revocation shared by the subscription end paths (P18-B3).
 *
 * Lives apart from any one Action because the cancel route and the expiry sweep
 * must not drift on the same invariant: revoke ONLY the role THIS row applied
 * (the copy made at activation), and only when no other live period still needs
 * it. Duplicating it in a second Action is how a tier rename starts stranding
 * roles again.
 */
trait RevokesSubscriptionRole
{
    /**
     * The status write already ran, so `currentlyActive` no longer sees the ended
     * row; a hit here is a different live period carrying the same role.
     */
    private function roleStillLive(Member $member, string $roleName): bool
    {
        return $member->subscriptions()->currentlyActive()->where('role_name', $roleName)->exists();
    }

    private function removeRole(User $user, string $roleName): void
    {
        $target = array_values(
            array_filter($user->roles->pluck('name')->all(), fn (string $name): bool => $name !== $roleName)
        );

        $user->syncRoles(RoleLookup::findMany($target)->values()->all());
    }
}
