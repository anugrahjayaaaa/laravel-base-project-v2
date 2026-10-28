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
     * Monitoring dashboards — read-only, `.view` only, deliberately no
     * `.manage`: both dashboards read a status and run nothing, so a second
     * action would be a row no `can()` call ever checks — the exact "row that
     * lies in the permissions UI" this class exists to prevent (same argument
     * as Phase 11 §3 for `pulse.view`).
     *
     * Two consts, not one `MONITORING`: every other const here is one resource
     * prefix, which is what `grouped()` renders as one heading.
     *
     * @var array<int, string>
     */
    private const PULSE = [
        'pulse.view',
    ];

    /** @var array<int, string> */
    private const HEALTH = [
        'health.view',
    ];

    /**
     * API documentation — the Scramble UI at `/docs/api`.
     *
     * `.view` only, and no `.manage`: the document is generated from the routes,
     * so there is nothing to edit. A second action would be a row no `can()` call
     * ever checks.
     *
     * Read it as a disclosure permission, not a convenience one: the document
     * names every endpoint, every field and every status code, which is the map
     * an attacker would otherwise have to build. That is why it is a permission
     * rather than "any authenticated user" — the same reasoning that put
     * `audit.view` above the user list.
     *
     * @var array<int, string>
     */
    private const API_DOCS = [
        'api_docs.view',
    ];

    /**
     * Queue monitoring (Vantage) — Phase 11 §13.
     *
     * Two permissions, not one, on the `audit.view`/`audit.export` rule: reading
     * the queue is not the same act as re-queueing a job. `queues.view` opens the
     * dashboard; `queues.retry` guards the one mutating button on it, which
     * enqueues work again. Handing a reader the retry button would make every
     * queue reader able to trigger a job — the same widening
     * `notifications.send_test` avoids.
     *
     * @var array<int, string>
     */
    private const QUEUES = [
        'queues.view',
        'queues.retry',
    ];

    /**
     * Which feature flag owns which resource.
     *
     * A permission for a module that is switched off must not be handed out:
     * the role matrix hides it and `FeatureToggleAction` revokes it from every
     * role when the flag goes off. Both need one answer to "whose permission is
     * this", and this map is that answer.
     *
     * Keyed by RESOURCE, not by permission name, for the same reason
     * `grouped()` derives the prefix instead of listing it: a new
     * `users.impersonate` inherits its module's flag with no entry here, so the
     * map cannot rot one permission at a time. A new RESOURCE does need a line
     * — `PermissionFeatureMapTest` fails when one is missing, which is the
     * point: silently unmapped is the one outcome that must be loud.
     *
     * `features` is deliberately absent and is the only such resource. The flag
     * management page must stay reachable and editable from any role the
     * operator handed it to: gating `features.view`/`features.manage` on the
     * `features` flag would revoke both the moment it was switched off, and the
     * routes are not gated on it either (see routes/web.php) — the page that
     * re-enables a flag cannot be one of the flags it re-enables.
     *
     * `sessions` own no permissions, so they have no entry either.
     *
     * @var array<string, string> resource prefix => flag slug
     */
    private const FEATURE_BY_RESOURCE = [
        'users' => 'users',
        'roles' => 'roles',
        'permissions' => 'permissions',
        'settings' => 'settings',
        'notifications' => 'notifications',
        // The resource is `audit`, the flag is `activity_logs`: the viewer was
        // named after the module before the flag existed. Spelled out here
        // rather than pluralised from the prefix, which is exactly how the two
        // would silently drift.
        'audit' => 'activity_logs',
        'pulse' => 'pulse',
        'health' => 'health',
        'queues' => 'vantage',
    ];

    /**
     * Resources with no feature flag behind them.
     *
     * Not a fallback for a forgotten entry — `PermissionFeatureMapTest` asserts
     * the set of unmapped resources is exactly this list, so a new resource
     * with no flag has to be added here on purpose or the suite goes red.
     *
     * @var array<int, string>
     */
    private const UNMAPPED_RESOURCES = [
        // See FEATURE_BY_RESOURCE: the toggle page cannot be behind a toggle.
        'features',
        // The document describes every module, so it cannot hang off one of
        // them: switching `users` off must not take away the page that says
        // which endpoints exist. There is no `api_docs` flag to map it to, and
        // inventing one would make the docs disappear with the module whose
        // documentation was being read.
        'api_docs',
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
            ...self::PULSE,
            ...self::HEALTH,
            ...self::QUEUES,
            ...self::API_DOCS,
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

    /**
     * The flag that owns a permission, or null when it has none.
     *
     * Null means "unconditional", not "unknown": `features.view` is visible and
     * assignable whatever the store says, because no flag governs it.
     */
    public static function featureFor(string $permission): ?string
    {
        return self::FEATURE_BY_RESOURCE[Str::before($permission, '.')] ?? null;
    }

    /**
     * Every permission a flag owns — what switching it off takes away.
     *
     * @return array<int, string>
     */
    public static function forFeature(string $flag): array
    {
        return array_values(array_filter(
            self::all(),
            fn (string $name): bool => self::featureFor($name) === $flag
        ));
    }

    /**
     * The permissions whose module is switched on — what the UI offers.
     *
     * @return array<int, string>
     */
    public static function visibleNames(): array
    {
        return self::partition()[0];
    }

    /**
     * The permissions whose module is switched off — what a toggle revokes.
     *
     * @return array<int, string>
     */
    public static function namesForInactiveFeatures(): array
    {
        return self::partition()[1];
    }

    /**
     * The unmapped resources, for the test that keeps this map honest.
     *
     * @return array<int, string>
     */
    public static function unmappedResources(): array
    {
        return self::UNMAPPED_RESOURCES;
    }

    /**
     * Split the catalogue by whether the owning flag is on.
     *
     * ONE store read for the whole catalogue: `activeMap()` takes every flag
     * this class names and reads them in a single `WHERE name IN (...)`. Asking
     * `FeatureCatalog::isActive()` per permission would be one query per
     * permission on a page that renders the whole matrix.
     *
     * @return array{0: array<int, string>, 1: array<int, string>} [visible, hidden]
     */
    private static function partition(): array
    {
        $flags = FeatureCatalog::activeMap(array_values(self::FEATURE_BY_RESOURCE));

        $visible = [];
        $hidden = [];

        foreach (self::all() as $name) {
            $flag = self::featureFor($name);

            if ($flag === null || ($flags[$flag] ?? false)) {
                $visible[] = $name;

                continue;
            }

            $hidden[] = $name;
        }

        return [$visible, $hidden];
    }
}
