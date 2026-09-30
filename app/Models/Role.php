<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\SystemRole;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Application Role model extending Spatie's base Role.
 *
 * Carries the `is_system` accessor so the controller doesn't have to
 * mutate collection items after loading — and so views get the same
 * answer everywhere SystemRole::isSystem() is needed.
 *
 * ## Soft delete
 *
 * `SoftDeletes` is what makes a trashed role stop granting anything: Spatie
 * resolves roles through this model, so the global scope hides the row from
 * `$user->roles`, `hasRole()`, and every `can()` — with no change at the read
 * sites. It also makes the revocation auditable rather than silent, because the
 * assignments are detached explicitly in RoleDeleteAction (Spatie's own
 * `deleting` hook deliberately skips detach on a soft delete, so nothing would
 * clean the pivots for us).
 *
 * ponytail: a trashed role KEEPS its `role_has_permissions` rows, so restoring
 * it brings the permission set back intact. What does not come back is the
 * assignment — the users were detached at delete time, and reassigning them is a
 * deliberate act, not a side effect of an undo.
 */
class Role extends SpatieRole
{
    // role.created / role.updated / role.deleted / role.restored /
    // role.force_deleted, written by the Role actions inside their
    // transaction (DEP-003).
    use Auditable;
    use SoftDeletes;

    /**
     * Whether this role name is one the application depends on.
     */
    public function getIsSystemAttribute(): bool
    {
        return SystemRole::isSystem($this->name);
    }
}
