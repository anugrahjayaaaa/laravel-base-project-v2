<?php

namespace App\Concerns;

/**
 * Maps a bulk action to the permission it requires.
 *
 * The users and roles bulk bars both need the same decision — "this body asked
 * for `delete`; does the caller hold `users.delete` or `roles.delete`?" — and the
 * two sets differ only in the prefix. One table, two prefixes, no second place
 * for the mapping to drift.
 */
trait AuthorizesBulkAction
{
    /**
     * The suffix each bulk action shares. The full permission is the entity
     * prefix plus these, so a new action is added once and both bars get it.
     *
     * @var array<string, string>
     */
    private const BULK_ACTIONS = [
        'delete' => 'delete',
        'force_delete' => 'force_delete',
        'restore' => 'restore',
        'lock' => 'lock',
        'unlock' => 'unlock',
        'activate' => 'activate',
        'deactivate' => 'deactivate',
    ];

    /**
     * The actions this entity's bulk bar offers, before the permission check.
     *
     * Defaults to the whole map. An entity whose handler implements a subset
     * overrides it, because the map is shared and `members.lock` is not a
     * permission anybody can hold: the check below would 403 every normal
     * caller, while the superadmin's `Gate::before` sails past it and the
     * handler answers 200 with zero rows acted on.
     *
     * @return array<int, string>
     */
    protected static function bulkActionAllowlist(): array
    {
        return array_keys(self::BULK_ACTIONS);
    }

    /**
     * The entity prefix the caller uses, e.g. `users` or `roles`.
     */
    abstract protected function bulkEntityPrefix(): string;

    /**
     * Authorize the requested action against its own permission.
     *
     * False for an action that is not in the map. `rules()` rejects it afterwards,
     * but authorize() runs first, so an unknown action must not fall through to
     * a permissive default.
     */
    public function authorize(): bool
    {
        $action = (string) $this->input('action');

        if (! in_array($action, static::bulkActionAllowlist(), true)) {
            return false;
        }

        return $this->user()?->can($this->bulkEntityPrefix() . '.' . self::BULK_ACTIONS[$action]) ?? false;
    }

    /**
     * The bulk actions this request accepts, for the `action` validation rule.
     *
     * @return array<int, string>
     */
    public static function bulkActions(): array
    {
        return static::bulkActionAllowlist();
    }
}
