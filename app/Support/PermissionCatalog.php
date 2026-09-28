<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The permission catalogue — the single source of truth for what this
 * application can check.
 *
 * A permission is code, not data: it means nothing unless some `can()` call
 * somewhere names it. Keeping the list here means the seeder, the role form
 * matrix, and the tests all read the same names instead of three hand-typed
 * arrays that drift apart the way `password_mixed_case` and
 * `password_require_upper` did in Phase 5 (see PolicyKeyTest).
 *
 * Naming is `{resource}.{action}` — lowercase, dotted — and the resource prefix
 * is what `grouped()` groups on, so a new `resource.action` needs no entry
 * anywhere else to appear under its own heading.
 *
 * ponytail: a static list, not a DB-editable CRUD. The permissions table is the
 * storage, this class is the contract. Revisit when a tenant or plugin needs
 * runtime-defined permissions.
 */
class PermissionCatalog
{
    /**
     * User management — the only resource with a full action set.
     *
     * @var array<int, string>
     */
    private const USERS = [
        'users.view',
        'users.create',
        'users.update',
        'users.delete',
        'users.force_delete',
        'users.restore',
        'users.activate',
        'users.deactivate',
        'users.lock',
        'users.unlock',
        'users.assign_roles',
    ];

    /** @var array<int, string> */
    private const ROLES = [
        'roles.view',
        'roles.create',
        'roles.update',
        'roles.delete',
        'roles.assign_permissions',
    ];

    /** @var array<int, string> */
    private const PERMISSIONS = [
        'permissions.view',
    ];

    /** @var array<int, string> */
    private const SETTINGS = [
        'settings.view',
        'settings.manage',
    ];

    // ponytail: no `audit.*` or `features.*` yet — the audit viewer is Phase 10
    // and feature flags are Phase 7, and there is no route, controller or view
    // for either today. A permission nothing checks is a row that lies in the
    // permissions UI and grants nothing. Add each group in the same commit that
    // adds the page it guards.

    /**
     * Every permission name the application defines.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            ...self::USERS,
            ...self::ROLES,
            ...self::PERMISSIONS,
            ...self::SETTINGS,
        ];
    }

    /**
     * The catalogue grouped by resource prefix.
     *
     * The prefix is derived from the name, never listed separately — a
     * hardcoded `['users' => 'user']`-style map is exactly how a permission
     * ends up assigned to the wrong heading and nobody notices.
     *
     * @return array<string, array<int, string>> resource => permission names
     */
    public static function grouped(): array
    {
        $grouped = [];

        foreach (self::all() as $name) {
            $grouped[Str::before($name, '.')][] = $name;
        }

        return $grouped;
    }

    /**
     * Every permission belonging to one resource.
     *
     * @return array<int, string>
     */
    public static function forResource(string $resource): array
    {
        return self::grouped()[$resource] ?? [];
    }
}
