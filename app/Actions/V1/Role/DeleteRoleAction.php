<?php

namespace App\Actions\V1\Role;

use App\Models\Role;
use App\Models\RoleLookup;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\SystemRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Trash a role and revoke it from everyone holding it.
 *
 * Soft delete alone would NOT revoke anything an administrator can see. Spatie's
 * own `deleting` hook deliberately skips `detach()` when the model is not force
 * deleting, so the `model_has_roles` pivot rows survive and a later `restore()`
 * silently hands the role — and every permission it carries — back to whoever
 * held it. For a role that was retired on purpose, that is the wrong default: the
 * un-do has to be explicit, and the revocation has to be an event in the audit
 * trail rather than a side effect of a row update.
 *
 * So the policy is: trashing a role DEASSIGNS it. `$role->users()->detach()` runs
 * first, inside the transaction, and the audit row records how many people were
 * affected. Permissions stay on the role (`role_has_permissions` is untouched), so
 * `restore()` brings the definition back — but the assignment has to be redone by
 * hand, which is what makes the restore deliberate.
 *
 * The global SoftDeletes scope is what makes the revocation take effect on the
 * read side: Spatie resolves roles through App\Models\Role, so a trashed role is
 * absent from `$user->roles`, `hasRole()`, and every `can()` without a single
 * change at the call sites.
 *
 * **Landing someone on no role at all is not a neutral outcome.** A user with
 * zero roles holds zero permissions, so every gated screen 403s and the sidebar
 * empties — they can still log in, but the account is inert and nothing in the
 * UI says why. Retiring a role therefore falls back to
 * `registration_default_role` for exactly the people the revocation left with
 * nothing: the same value self-registration uses, read through the same
 * `SystemSetting` accessor, so there is one answer to "what does a new account
 * get" rather than two.
 *
 * This replaces the earlier decision to leave those accounts empty. That was
 * defensible while the only way to hold no role was a human ticking every box
 * off — a deliberate act with a visible result. Trashing a role is not
 * deliberate at the level of the individual: one admin action silently strips
 * access from N people who never saw the role picker.
 *
 * superadmin is never granted as the fallback. It is the one role whose grant
 * is restricted to superadmin actors by `AssignRolesAction`, and a side effect
 * of a role deletion is not an actor anyone authorised. If the configured
 * default is somehow superadmin, the account is left empty rather than promoted.
 */
class DeleteRoleAction
{
    /**
     * Trash a role, revoking it from every user holding it.
     *
     * @param  Role       $role
     * @param  User|null  $causer Who to attribute the audit record to
     * @param  bool       $force   Trash even while users still hold the role
     * @return Role        The trashed role, for the audit trail and the flash message
     *
     * @throws ValidationException When the role is a system role, or still has users
     */
    public function run(Role $role, ?User $causer = null, bool $force = false): Role
    {
        $this->validate($role, $force);

        DB::transaction(function () use ($role, $causer): void {
            // Read the holders BEFORE the detach — afterwards the relation is
            // empty and there is no way to ask who was affected.
            $holders = $role->users()->get();

            // First, so the pivot rows are gone even if the soft delete below is
            // the thing that fails. Spatie skips this on a non-force delete.
            $role->users()->detach();

            $role->delete();

            $reassigned = $this->reassignDefaultTo($holders);

            // Inside the transaction (DEP-003). `revoked_users` is the whole point
            // of the audit row: "a role was trashed" is not actionable during an
            // incident, "these 12 accounts lost report access" is.
            $role->audit('role.deleted', $causer, [
                'revoked_users' => $holders->count(),
                'reassigned_to_default' => $reassigned,
                'revoked_permissions' => $role->permissions()->count(),
            ]);
        });

        return $role;
    }

    /**
     * Put anyone the revocation left with no roles at all onto the default.
     *
     * Only accounts that would otherwise be left with NOTHING. A holder who
     * still has another role keeps it — trashing one role must not silently
     * rewrite somebody who is still perfectly well covered.
     *
     * Roles are assigned directly rather than through AssignRolesAction: that
     * action exists to police an actor-supplied grant, and this is a system
     * consequence with no payload. Routing it through would mean the
     * users.assign_roles check firing on a role deletion, and the
     * last-superadmin counter running against a user who is not being demoted.
     * The superadmin guard is still honoured explicitly, below.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, User>  $holders
     * @return int  How many accounts were moved onto the fallback
     */
    private function reassignDefaultTo($holders): int
    {
        $default = SystemSetting::getString('registration_default_role', SystemRole::USER);

        // Never promote as a side effect. If the configured default is
        // superadmin, leave the account empty rather than hand out the one role
        // whose grant is restricted to superadmin actors.
        if ($default === '' || $default === SystemRole::SUPERADMIN) {
            return 0;
        }

        $role = RoleLookup::find($default);

        if ($role === null) {
            return 0;
        }

        $reassigned = 0;

        foreach ($holders as $user) {
            // Re-read from the database: the relation on $user may be a stale
            // pre-detach snapshot, and the question is what they hold NOW.
            if ($user->fresh()->roles()->exists()) {
                continue;
            }

            $user->assignRole($role);
            $reassigned++;
        }

        return $reassigned;
    }

    /**
     * Refuse deletions that would break something.
     *
     * A role with users is refused by default because trashing it deassigns every
     * one of them — the deletion succeeds and each of those accounts silently
     * loses the access the role was carrying, which is the outcome an admin
     * clicking "Delete" least expects. `$force` is the deliberate override for a
     * role being retired; the action then records the count in the audit row.
     */
    private function validate(Role $role, bool $force): void
    {
        $validator = Validator::make([], []);

        if (SystemRole::isSystem($role->name)) {
            $validator->errors()->add('name', __('System roles cannot be deleted.'));

            throw new ValidationException($validator);
        }

        if ($force || $role->trashed()) {
            return;
        }

        $count = $role->users()->count();

        if ($count > 0) {
            $validator->errors()->add(
                'name',
                __('This role is still assigned to :count user(s). Trashing it removes the role from all of them.', ['count' => $count])
            );

            throw new ValidationException($validator);
        }
    }
}
