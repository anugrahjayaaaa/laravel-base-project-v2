<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
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
     * Every role the app can actually assign, ordered for a picker.
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
}
