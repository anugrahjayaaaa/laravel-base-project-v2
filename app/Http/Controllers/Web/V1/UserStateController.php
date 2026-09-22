<?php

namespace App\Http\Controllers\Web\V1;

use App\Actions\User\ActivateUserAction;
use App\Actions\User\DeactivateUserAction;
use App\Actions\User\LockUserAction;
use App\Actions\User\UnlockUserAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class UserStateController extends Controller
{
    public function __construct(
        private readonly ActivateUserAction $activateAction,
        private readonly DeactivateUserAction $deactivateAction,
        private readonly LockUserAction $lockAction,
        private readonly UnlockUserAction $unlockAction,
    ) {}

    public function activate(User $user): RedirectResponse
    {
        $this->activateAction->run($user);

        $this->audit('user.activated', $user, auth()->user(), ['target_id' => $user->id, 'target_email' => $user->email]);

        return back()->with('status', 'User activated successfully.');
    }

    public function deactivate(User $user): RedirectResponse
    {
        $this->deactivateAction->run($user, auth()->user());

        $this->audit('user.deactivated', $user, auth()->user(), ['target_id' => $user->id, 'target_email' => $user->email]);

        return back()->with('status', 'User deactivated successfully.');
    }

    public function lock(User $user): RedirectResponse
    {
        $this->lockAction->run($user);

        $this->audit('user.locked', $user, auth()->user(), ['target_id' => $user->id, 'target_email' => $user->email]);

        return back()->with('status', 'User locked successfully.');
    }

    public function unlock(User $user): RedirectResponse
    {
        $this->unlockAction->run($user, request()->ip(), request());

        $this->audit('user.unlocked', $user, auth()->user(), ['target_id' => $user->id, 'target_email' => $user->email]);

        return back()->with('status', 'User unlocked successfully.');
    }
}
