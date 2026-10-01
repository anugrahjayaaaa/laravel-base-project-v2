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
        $suffix = self::BULK_ACTIONS[(string) $this->input('action')] ?? null;

        if ($suffix === null) {
            return false;
        }

        return $this->user()?->can($this->bulkEntityPrefix() . '.' . $suffix) ?? false;
    }

    /**
     * The bulk actions this request accepts, for the `action` validation rule.
     *
     * @return array<int, string>
     */
    public static function bulkActions(): array
    {
        return array_keys(self::BULK_ACTIONS);
    }
}
