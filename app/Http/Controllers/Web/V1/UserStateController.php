<?php

namespace App\Http\Controllers\Web\V1;

use App\Actions\User\ActivateUserAction;
use App\Actions\User\DeactivateUserAction;
use App\Actions\User\LockUserAction;
use App\Actions\User\UnlockUserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\ActivateUserRequest;
use App\Http\Requests\User\DeactivateUserRequest;
use App\Http\Requests\User\LockUserRequest;
use App\Http\Requests\User\UnlockUserRequest;
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

    public function activate(ActivateUserRequest $request, User $user): RedirectResponse
    {
        $this->activateAction->run($user);

        return back()->with('status', 'User activated successfully.');
    }

    public function deactivate(DeactivateUserRequest $request, User $user): RedirectResponse
    {
        $this->deactivateAction->run($user, auth()->user());

        return back()->with('status', 'User deactivated successfully.');
    }

    public function lock(LockUserRequest $request, User $user): RedirectResponse
    {
        $this->lockAction->run($user);

        return back()->with('status', 'User locked successfully.');
    }

    public function unlock(UnlockUserRequest $request, User $user): RedirectResponse
    {
        $this->unlockAction->run($user, request()->ip(), request());

        return back()->with('status', 'User unlocked successfully.');
    }
}
