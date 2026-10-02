<?php

namespace App\Http\Controllers\Web\V1;

use App\Actions\V1\User\UserActivateAction;
use App\Actions\V1\User\UserDeactivateAction;
use App\Actions\V1\User\UserLockAction;
use App\Actions\V1\User\UserUnlockAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

/**
 * User state controller,activate, deactivate, lock, unlock users (web).
 */
class UserStateController extends Controller
{
    /**
     * @param  UserActivateAction  $activateAction
     * @param  UserDeactivateAction  $deactivateAction
     * @param  UserLockAction  $lockAction
     * @param  UserUnlockAction  $unlockAction
     */
    public function __construct(
        private readonly UserActivateAction $activateAction,
        private readonly UserDeactivateAction $deactivateAction,
        private readonly UserLockAction $lockAction,
        private readonly UserUnlockAction $unlockAction,
    ) {
    }

    /**
     * Activate a user.
     *
     * @param  User  $user
     * @return RedirectResponse
     */
    public function activate(User $user): RedirectResponse
    {
        $this->activateAction->run($user, auth()->user());

        return back()->with('status', 'User activated successfully.');
    }

    /**
     * Deactivate a user.
     *
     * @param  User  $user
     * @return RedirectResponse
     */
    public function deactivate(User $user): RedirectResponse
    {
        $this->deactivateAction->run($user, auth()->user());

        return back()->with('status', 'User deactivated successfully.');
    }

    /**
     * Lock a user account.
     *
     * @param  User  $user
     * @return RedirectResponse
     */
    public function lock(User $user): RedirectResponse
    {
        $this->lockAction->run($user, auth()->user());

        return back()->with('status', 'User locked successfully.');
    }

    /**
     * Unlock a user account.
     *
     * @param  User  $user
     * @return RedirectResponse
     */
    public function unlock(User $user): RedirectResponse
    {
        $this->unlockAction->run($user, auth()->user(), request()->ip(), request());

        return back()->with('status', 'User unlocked successfully.');
    }
}
