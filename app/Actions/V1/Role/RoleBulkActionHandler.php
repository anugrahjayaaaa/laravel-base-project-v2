<?php

namespace App\Actions\V1\Role;

use App\Actions\V1\BulkAction\BulkActionHandler;
use App\Models\Role;
use App\Support\SystemRole;
use Illuminate\Database\Eloquent\Collection;

/**
 * Bulk action handler for Role model operations.
 *
 * Mirrors UserBulkActionHandler: getValidItems() is the security boundary, since
 * the browser's dropdown is only a convenience. A system role is excluded from
 * delete and force_delete here rather than trusted to a disabled checkbox, and a
 * live role is excluded from restore/force_delete for the same reason.
 */
class RoleBulkActionHandler implements BulkActionHandler
{
    /**
     * Filter IDs to valid roles for the given action.
     *
     * @param  array<int>  $ids
     * @param  string      $action
     * @return Collection
     */
    public function getValidItems(array $ids, string $action): Collection
    {
        $roles = Role::withTrashed()->whereIn('id', $ids)->get();

        return $roles->filter(function (Role $role) use ($action) {
            $isSystem = SystemRole::isSystem($role->name);

            return match ($action) {
                // force: the single-row web path forces because the confirm
                // modal in front of it is the deliberate override (see
                // RoleController::destroy). The bulk modal is that same
                // deliberate override, spelled out for N roles at once, so
                // getValidItems admits a populated role and executeBulk passes
                // the flag — otherwise selecting 30 roles and confirming would
                // refuse all 30 and look broken.
                'delete' => ! $role->trashed() && ! $isSystem,
                'restore' => $role->trashed() && ! $isSystem,
                'force_delete' => $role->trashed() && ! $isSystem,
                default => false,
            };
        });
    }

    /**
     * Execute the bulk operation on valid role IDs.
     *
     * @param  string  $action
     * @param  array<int>  $ids
     */
    public function executeBulk(string $action, array $ids): void
    {
        $causer = auth()->user();

        $roles = Role::withTrashed()->whereIn('id', $ids)->get();

        match ($action) {
            'delete' => $roles->each(fn (Role $role) => app(RoleDeleteAction::class)->run($role, $causer, force: true)),
            'restore' => $roles->each(fn (Role $role) => app(RoleRestoreAction::class)->run($role, $causer)),
            'force_delete' => $roles->each(fn (Role $role) => app(RoleForceDeleteAction::class)->run($role, $causer)),
            default => null,
        };
    }

    /**
     * Get the technical audit event name.
     *
     * Returns an empty string deliberately. The per-role actions already write
     * `role.deleted` / `role.restored` / `role.force_deleted` with the
     * revoked-user counts, so an aggregate row would be a second record of one
     * bulk click — and one whose subject is nobody. The controller skips the
     * aggregate write on a falsy event, and the role controllers do not call
     * `auditBulk()` at all, so nothing reads this value today; it stays empty so
     * that if a caller ever does, it adds no row rather than a duplicate.
     *
     * This is the same mechanism `UserBulkActionHandler` uses, and the reason it
     * returns `''` for exactly the operations whose action audits itself.
     *
     * @param  string  $action
     * @return string
     */
    public function getAuditEvent(string $action): string
    {
        return '';
    }

    /**
     * Get the human-readable label for the action result.
     *
     * @param  string  $action
     * @return string
     */
    public function getAuditLabel(string $action): string
    {
        return match ($action) {
            'delete' => 'moved to trash',
            'force_delete' => 'permanently deleted',
            'restore' => 'restored',
            default => 'Action completed',
        };
    }

    /**
     * Get cache keys to invalidate after the bulk operation.
     *
     * @return string[]
     */
    public function getCacheKeys(): array
    {
        return ['role_index_counts'];
    }

    /**
     * Batch invalidate sessions/tokens for the given role IDs.
     *
     * No-op, and deliberately so: trashing a role detaches it from its users
     * rather than disabling their accounts, so nobody is logged out and no
     * session needs to die. A future action that DOES disable accounts must add
     * the users table here.
     *
     * @param  array<int>  $ids
     */
    public function batchInvalidateSessions(array $ids): void
    {
        //
    }

    /**
     * Get the list of actions that require session invalidation.
     *
     * @return string[]
     */
    public function getSessionInvalidationActions(): array
    {
        return [];
    }
}
