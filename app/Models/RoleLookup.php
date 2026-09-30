<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use App\Support\SystemRole;
use Spatie\Permission\Guard;

/**
 * Reads roles for the guard Spatie actually resolves for a User.
 *
 * Spatie keys a role by (name, guard_name), so the same name can exist twice.
 * Reading roles without naming a guard is what made the role pickers render
 * `superadmin` and `admin` twice, and made CreateUserAction's
 * `where('name', ...)->first()` return whichever row came first — possibly one
 * no permission check ever consults.
 *
 * `config('auth.defaults.guard')` is the wrong thing to read here: it is
 * mutated per request (`Sanctum::actingAs()` calls `Auth::shouldUse('sanctum')`),
 * and Spatie does not take it at face value. It intersects the default with the
 * guards the model can actually authenticate under, so it resolves `web` even
 * while the default reads `sanctum`. Reading the config directly therefore
 * disagreed with the very permission checks the roles are meant to feed.
 * Guard::getDefaultName() is the resolver Spatie itself uses, so asking it
 * cannot drift.
 */
class RoleLookup
{
    /**
     * The guard name Spatie resolves for a User, which is not always the
     * configured default.
     */
    public static function guard(): string
    {
        return Guard::getDefaultName(User::class);
    }

    /**
     * Whether the viewer is one of the superadmins.
     *
     * The visibility rule below is a single `if`, and the gate is the same
     * question everywhere, so it is asked here once.
     */
    public static function viewerIsSuperAdmin(?User $viewer): bool
    {
        return $viewer?->hasRole(SystemRole::SUPERADMIN) === true;
    }

    /**
     * Roles the viewer may see, ordered for a picker.
     *
     * The superadmin role is invisible to anyone who is not a superadmin: the
     * app is expected to run with exactly one, and a delegated admin should not
     * be able to see it sitting in the list, pick it, or count it. Granting it
     * is separately refused in AssignRolesAction — this is the UI half of that,
     * and on its own it protects nothing.
     *
     * @return Collection<int, \Spatie\Permission\Models\Role>
     */
    public static function visibleTo(?User $viewer): Collection
    {
        $query = Role::query()
            ->where('guard_name', static::guard())
            ->orderBy('name');

        if (! static::viewerIsSuperAdmin($viewer)) {
            $query->where('name', '!=', SystemRole::SUPERADMIN);
        }

        return $query->get();
    }

    /**
     * Every role the app can actually assign, ordered for a picker.
     *
     * Guard scoping only — it says nothing about who may see the role. Use
     * visibleTo() for anything the viewer sees.
     *
     * @return Collection<int, \Spatie\Permission\Models\Role>
     */
    public static function assignable(): Collection
    {
        return Role::query()
            ->where('guard_name', static::guard())
            ->orderBy('name')
            ->get();
    }

    /**
     * Find one role by name on that guard, or null when the name exists only on
     * another guard.
     */
    public static function find(string $name): ?Role
    {
        return Role::query()
            ->where('name', $name)
            ->where('guard_name', static::guard())
            ->first();
    }

    /**
     * Find many roles by name on that guard, in ONE query.
     *
     * `find()` in a loop is an N+1: assigning 20 roles cost 20 selects. This
     * asks for the whole set at once and keys it by name, so the caller pays
     * one query whether the payload carries one role or twenty.
     *
     * Order is NOT preserved — it comes back in whatever order the database
     * returns, keyed by name for the caller to look up. Callers that care about
     * order (a picker) want `assignable()` or `visibleTo()` instead.
     *
     * @param  array<int, string>  $names
     * @return Collection<string, Role>  Keyed by name; a name with no role on
     *                                    this guard is simply absent
     */
    public static function findMany(array $names): Collection
    {
        $names = array_values(array_unique($names));

        if ($names === []) {
            return new Collection();
        }

        return Role::query()
            ->where('guard_name', static::guard())
            ->whereIn('name', $names)
            ->get()
            ->keyBy('name');
    }
}
