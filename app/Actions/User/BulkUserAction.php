<?php

namespace App\Actions\User;

use App\Enums\UserStatusEnum;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Facades\Activity;

class BulkUserAction
{
    public function __construct(
        private readonly DeleteUserAction $deleteAction,
        private readonly ForceDeleteUserAction $forceDeleteAction,
        private readonly RestoreUserAction $restoreAction,
        private readonly LockUserAction $lockAction,
        private readonly UnlockUserAction $unlockAction,
        private readonly ActivateUserAction $activateAction,
        private readonly DeactivateUserAction $deactivateAction,
    ) {}

    public function run(string $action, array $userIds, User $causer): array
    {
        $users = User::withTrashed()->whereIn('id', $userIds)->get();
        $count = 0;

        foreach ($users as $user) {
            $executed = match ($action) {
                'delete' => !$user->trashed() && $this->deleteAction->run($user, $causer),
                'force_delete' => $user->trashed() && $this->forceDeleteAction->run($user, $causer),
                'restore' => $user->trashed() && $this->restoreAction->run($user),
                'lock' => !$user->trashed() && $user->isActiveUser() && $this->lockAction->run($user),
                'unlock' => !$user->trashed() && $user->getStatus()->value === UserStatusEnum::LOCKED->value && $this->unlockAction->run($user),
                'activate' => !$user->trashed() && $user->getStatus()->value === UserStatusEnum::INACTIVE->value && $this->activateAction->run($user),
                'deactivate' => !$user->trashed() && $user->isActiveUser() && $this->deactivateAction->run($user, $causer),
                default => false,
            };

            if ($executed) {
                $count++;
                activity()
                    ->performedOn($user)
                    ->causedBy($causer)
                    ->log("user.{$action}");
            }
        }

        $label = match ($action) {
            'delete' => 'Users moved to trash',
            'force_delete' => 'Users permanently deleted',
            'restore' => 'Users restored',
            'lock' => 'Users locked',
            'unlock' => 'Users unlocked',
            'activate' => 'Users activated',
            'deactivate' => 'Users deactivated',
            default => 'Action completed',
        };

        return ['count' => $count, 'label' => $label];
    }
}