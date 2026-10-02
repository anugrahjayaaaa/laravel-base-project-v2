<?php

namespace App\Actions\V1\User;

use App\Enums\UserStatusEnum;
use App\Models\User;
use App\Actions\V1\BulkAction\BulkActionHandler;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bulk action handler for User model operations.
 */
class UserBulkActionHandler implements BulkActionHandler
{
    /**
     * Filter IDs to valid users for the given action.
     *
     * @param  array<int>  $ids
     * @param  string      $action
     * @return Collection
     */
    public function getValidItems(array $ids, string $action): Collection
    {
        $users = User::withTrashed()->whereIn('id', $ids)->get();

        return $users->filter(function ($user) use ($action) {
            return match ($action) {
                'delete' => !$user->trashed(),
                'force_delete' => $user->trashed(),
                'restore' => $user->trashed(),
                'lock' => !$user->trashed() && $user->isActiveUser(),
                'unlock' => !$user->trashed() && $user->getStatus()->value === UserStatusEnum::LOCKED->value,
                'activate' => !$user->trashed() && $user->getStatus()->value === UserStatusEnum::INACTIVE->value,
                'deactivate' => !$user->trashed() && $user->isActiveUser(),
                default => false,
            };
        });
    }

    /**
     * Execute the bulk operation on valid user IDs.
     *
     * @param  string  $action
     * @param  array<int>  $ids
     */
    public function executeBulk(string $action, array $ids): void
    {
        match ($action) {
            'lock' => User::whereIn('id', $ids)->update(['is_locked' => true]),
            'unlock' => User::whereIn('id', $ids)->update(['is_locked' => false]),
            'activate' => User::whereIn('id', $ids)->update(['is_active' => true, 'is_locked' => false]),
            // NOT a raw UPDATE. Deactivating writes `is_active`, and an inactive
            // superadmin cannot log in — so this column is load-bearing for the
            // last-superadmin invariant. The single-user path enforces it inside
            // UserDeactivateAction; a bulk `update()` here bypassed that action
            // completely, so the same guard the UI enforced was absent from the
            // dropdown one screen above. Routing through the action means the
            // guard lives in one place instead of two.
            'deactivate' => $this->deactivateUsers($ids),
            'delete' => $this->deleteUsers($ids),
            'restore' => $this->restoreUsers($ids),
            'force_delete' => $this->forceDeleteUsers($ids),
        };
    }

    /**
     * Get the technical audit event name.
     *
     * Empty for the actions whose per-user mutation is already audited by the
     * action it loops: `deleteUsers`, `restoreUsers`, `forceDeleteUsers` and
     * `deactivateUsers` each run one of the user actions, and each of those
     * writes its own `user.*` row per subject inside its own transaction. An
     * aggregate row here would name the same subjects a second time and add
     * none of the properties the action recorded. The controllers skip the
     * aggregate write on a falsy event, which is the same mechanism
     * `RoleBulkActionHandler` relies on.
     *
     * `lock` and `unlock` keep the aggregate row: they still write their column
     * directly through `executeBulk`, with no action behind them to audit.
     *
     * @param  string  $action
     * @return string
     */
    public function getAuditEvent(string $action): string
    {
        return in_array($action, ['delete', 'restore', 'force_delete', 'deactivate'], true)
            ? ''
            : "user.{$action}";
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
            'delete' => 'Users moved to trash',
            'force_delete' => 'Users permanently deleted',
            'restore' => 'Users restored',
            'lock' => 'Users locked',
            'unlock' => 'Users unlocked',
            'activate' => 'Users activated',
            'deactivate' => 'Users deactivated',
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
        return UserIndexAction::cacheKeys();
    }

    /**
     * Batch invalidate sessions/tokens for the given user IDs.
     *
     * @param  array<int>  $ids
     */
    public function batchInvalidateSessions(array $ids): void
    {
        DB::table('sessions')->whereIn('user_id', $ids)->delete();
        DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->whereIn('tokenable_id', $ids)
            ->delete();
    }

    /**
     * Get the list of actions that require session invalidation.
     *
     * @return string[]
     */
    public function getSessionInvalidationActions(): array
    {
        return ['lock', 'deactivate', 'delete'];
    }

    /**
     * Deactivate each user through the action, not around it.
     *
     * Looping the action means the last-superadmin guard inside it applies to
     * the bulk bar as well as the row button. A refusal aborts the whole batch
     * with the exception, which is the correct outcome: partially deactivating
     * a selection that included the last superadmin would be worse than doing
     * nothing, and the transaction-free loop leaves the earlier rows already
     * written either way — so the guard firing is what stops the half-applied
     * state from ever starting.
     *
     * @param  array<int>  $ids
     */
    private function deactivateUsers(array $ids): void
    {
        $users = User::withTrashed()->whereIn('id', $ids)->get();

        foreach ($users as $user) {
            app(UserDeactivateAction::class)->run($user, auth()->user());
        }
    }

    private function deleteUsers(array $ids): void
    {
        $users = User::withTrashed()->whereIn('id', $ids)->get();
        foreach ($users as $user) {
            app(UserDeleteAction::class)->run($user, auth()->user());
        }
    }

    private function restoreUsers(array $ids): void
    {
        $users = User::withTrashed()->whereIn('id', $ids)->get();
        foreach ($users as $user) {
            app(UserRestoreAction::class)->run($user, causer: auth()->user());
        }
    }

    private function forceDeleteUsers(array $ids): void
    {
        $users = User::withTrashed()->whereIn('id', $ids)->get();
        foreach ($users as $user) {
            app(UserForceDeleteAction::class)->run($user, auth()->user());
        }
    }
}
