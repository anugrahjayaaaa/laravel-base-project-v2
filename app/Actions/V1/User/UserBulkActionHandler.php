<?php

namespace App\Actions\V1\User;

use App\Enums\UserStatusEnum;
use App\Models\User;
use App\Actions\V1\BulkAction\BulkActionHandler;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class UserBulkActionHandler implements BulkActionHandler
{
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

    public function executeBulk(string $action, array $ids): void
    {
        match ($action) {
            'lock' => User::whereIn('id', $ids)->update(['is_locked' => true]),
            'unlock' => User::whereIn('id', $ids)->update(['is_locked' => false]),
            'activate' => User::whereIn('id', $ids)->update(['is_active' => true, 'is_locked' => false]),
            'deactivate' => User::whereIn('id', $ids)->update(['is_active' => false]),
            'delete' => $this->deleteUsers($ids),
            'restore' => $this->restoreUsers($ids),
            'force_delete' => $this->forceDeleteUsers($ids),
        };
    }

    public function getAuditEvent(string $action): string
    {
        return "user.{$action}";
    }

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

    public function getCacheKeys(): array
    {
        return ['user_index_counts'];
    }

    public function batchInvalidateSessions(array $ids): void
    {
        DB::table('sessions')->whereIn('user_id', $ids)->delete();
        DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->whereIn('tokenable_id', $ids)
            ->delete();
    }

    public function getSessionInvalidationActions(): array
    {
        return ['lock', 'deactivate', 'delete'];
    }

    private function deleteUsers(array $ids): void
    {
        $users = User::withTrashed()->whereIn('id', $ids)->get();
        foreach ($users as $user) {
            app(DeleteUserAction::class)->run($user, auth()->user());
        }
    }

    private function restoreUsers(array $ids): void
    {
        $users = User::withTrashed()->whereIn('id', $ids)->get();
        foreach ($users as $user) {
            app(RestoreUserAction::class)->run($user);
        }
    }

    private function forceDeleteUsers(array $ids): void
    {
        $users = User::withTrashed()->whereIn('id', $ids)->get();
        foreach ($users as $user) {
            app(ForceDeleteUserAction::class)->run($user, auth()->user());
        }
    }
}
