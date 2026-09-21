<?php

namespace App\Http\Controllers\Web\V1;

use App\Actions\User\ActivateUserAction;
use App\Actions\User\DeactivateUserAction;
use App\Actions\User\LockUserAction;
use App\Actions\User\UnlockUserAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Facades\Activity;

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

        activity('user.activated')
            ->causedBy(auth()->user())
            ->withProperties(['target_id' => $user->id, 'target_email' => $user->email])
            ->log('user.activated');

        return back()->with('status', 'User activated successfully.');
    }

    public function deactivate(User $user): RedirectResponse
    {
        $this->deactivateAction->run($user, auth()->user());

        activity('user.deactivated')
            ->causedBy(auth()->user())
            ->withProperties(['target_id' => $user->id, 'target_email' => $user->email])
            ->log('user.deactivated');

        return back()->with('status', 'User deactivated successfully.');
    }

    public function lock(User $user): RedirectResponse
    {
        $this->lockAction->run($user);

        activity('user.locked')
            ->causedBy(auth()->user())
            ->withProperties(['target_id' => $user->id, 'target_email' => $user->email])
            ->log('user.locked');

        return back()->with('status', 'User locked successfully.');
    }

    public function unlock(User $user): RedirectResponse
    {
        $this->unlockAction->run($user, request()->ip(), request());

        activity('user.unlocked')
            ->causedBy(auth()->user())
            ->withProperties(['target_id' => $user->id, 'target_email' => $user->email])
            ->log('user.unlocked');

        return back()->with('status', 'User unlocked successfully.');
    }
}
