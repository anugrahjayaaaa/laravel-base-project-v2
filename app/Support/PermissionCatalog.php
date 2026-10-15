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
        'roles.force_delete',
        'roles.restore',
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

    /**
     * Notifications & mail — the transport every outgoing message uses, plus
     * the send-test escape hatch.
     *
     * ponytail: `view` is read-only configuration, `manage` writes it, and
     * `send_test` is separate because mailing an arbitrary address a user typed
     * is an abuse vector, not a subset of configuring the transport.
     *
     * @var array<int, string>
     */
    private const NOTIFICATIONS = [
        'notifications.view',
        'notifications.manage',
        'notifications.send_test',
    ];

    /** @var array<int, string> */
    private const FEATURES = [
        'features.view',
        'features.manage',
    ];

    /**
     * Audit trail — the read-only viewer over the audit log.
     *
     * ponytail: `.export` is separate from `.view` for the reason
     * `notifications.send_test` is separate from `notifications.manage` — an
     * export moves the whole table, including every actor's IP and user agent,
     * out of the application into a file. Reading rows is not the same act as
     * extracting them, and one permission would let a read-only auditor pull a
     * copy of everything they can already see.
     *
     * `audit.view` is not a generic read permission: it is access to behavioural
     * data about staff, so it belongs to admin/superadmin rather than to any
     * role that can read the user list.
     *
     * @var array<int, string>
     */
    private const AUDIT = [
        'audit.view',
        'audit.export',
    ];

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
            ...self::NOTIFICATIONS,
            ...self::FEATURES,
            ...self::AUDIT,
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
