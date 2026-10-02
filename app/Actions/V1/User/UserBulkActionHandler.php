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
        // Every branch loops an action that writes its own audit row, and none
        // of them writes its column directly any more.
        //
        // The three state operations used to be `User::whereIn(...)->update()`.
        // That made them the last user mutations with no action behind them, and
        // the two consequences followed from that: the guards the row buttons
        // enforced were absent from the bulk bar (locking an inactive user,
        // activating a locked one), and the only record of the change was an
        // aggregate row the controller wrote after this returned — outside the
        // transaction, carrying no properties, naming no target. One code path
        // now serves both entry points.
        //
        // `getValidItems()` has already narrowed each selection to users the
        // matching action accepts, so a loop cannot trip the action's own guard:
        // lock admits only active users, activate only inactive ones (a locked
        // user resolves to LOCKED, which precedes INACTIVE in the enum).
        match ($action) {
            'lock' => $this->lockUsers($ids),
            'unlock' => $this->unlockUsers($ids),
            'activate' => $this->activateUsers($ids),
            'deactivate' => $this->deactivateUsers($ids),
            'delete' => $this->deleteUsers($ids),
            'restore' => $this->restoreUsers($ids),
            'force_delete' => $this->forceDeleteUsers($ids),
        };
    }

    /**
     * Get the technical audit event name.
     *
     * Empty, and no longer per-action: every branch of `executeBulk` loops an
     * action that writes its own `user.*` row per subject inside its own
     * transaction, so an aggregate row here would name the same subjects a
     * second time and add none of the properties the action recorded.
     *
     * `lock`, `unlock` and `activate` used to be the exception — they wrote
     * their column directly, so nothing else recorded them. Now they are not.
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
        // `invalidateSessions: false` because `BulkActionProcessor` already
        // calls `batchInvalidateSessions()` for every action in
        // `getSessionInvalidationActions()` — once for the whole selection, in
        // two queries. The action would otherwise delete the same sessions per
        // user on top of that.
        $this->eachUser($ids, fn (User $user, User $causer) => app(UserDeactivateAction::class)
            ->run($user, $causer, invalidateSessions: false));
    }

    /**
     * Lock each user through `UserLockAction`.
     *
     * `invalidateSessions: false` for the same reason as `deactivateUsers` —
     * the processor already batch-invalidates for `lock`.
     *
     * @param  array<int>  $ids
     */
    private function lockUsers(array $ids): void
    {
        $this->eachUser($ids, fn (User $user, User $causer) => app(UserLockAction::class)
            ->run($user, $causer, invalidateSessions: false));
    }

    /**
     * Unlock each user through `UserUnlockAction`.
     *
     * No session invalidation, and none is needed: unlocking restores access
     * rather than revoking it, and `lock` is not in
     * `getSessionInvalidationActions()` — so the token and session a lock
     * killed are not resurrected here.
     *
     * @param  array<int>  $ids
     */
    private function unlockUsers(array $ids): void
    {
        $this->eachUser($ids, fn (User $user, User $causer) => app(UserUnlockAction::class)
            ->run($user, $causer));
    }

    /**
     * Activate each user through `UserActivateAction`.
     *
     * Clears `is_locked` as well as setting `is_active`, exactly as the raw
     * `update()` this replaced did — the action owns the column pair, so the
     * bulk bar and the row button cannot drift apart on it.
     *
     * @param  array<int>  $ids
     */
    private function activateUsers(array $ids): void
    {
        $this->eachUser($ids, fn (User $user, User $causer) => app(UserActivateAction::class)
            ->run($user, $causer));
    }

    /**
     * Trash each user through `UserDeleteAction`.
     *
     * @param  array<int>  $ids
     */
    private function deleteUsers(array $ids): void
    {
        $this->eachUser($ids, fn (User $user, User $causer) => app(UserDeleteAction::class)
            ->run($user, $causer));
    }

    /**
     * Restore each user through `UserRestoreAction`.
     *
     * @param  array<int>  $ids
     */
    private function restoreUsers(array $ids): void
    {
        $this->eachUser($ids, fn (User $user, User $causer) => app(UserRestoreAction::class)
            ->run($user, causer: $causer));
    }

    /**
     * Permanently remove each user through `UserForceDeleteAction`.
     *
     * @param  array<int>  $ids
     */
    private function forceDeleteUsers(array $ids): void
    {
        $this->eachUser($ids, fn (User $user, User $causer) => app(UserForceDeleteAction::class)
            ->run($user, $causer));
    }

    /**
     * Run one action per selected user, attributing every record to the caller.
     *
     * One loop for all seven branches. They differed only in which action they
     * called, and six near-identical copies of fetch-then-loop is how three of
     * them ended up bypassing the actions entirely.
     *
     * @param  array<int>  $ids
     * @param  callable(User, User): mixed  $callback
     */
    private function eachUser(array $ids, callable $callback): void
    {
        $users = User::withTrashed()->whereIn('id', $ids)->get();
        $causer = auth()->user();

        foreach ($users as $user) {
            $callback($user, $causer);
        }
    }
}
