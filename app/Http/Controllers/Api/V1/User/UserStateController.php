<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Actions\User\ActivateUserAction;
use App\Actions\User\DeactivateUserAction;
use App\Actions\User\LockUserAction;
use App\Actions\User\UnlockUserAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Spatie\Activitylog\Facades\Activity;
use App\Models\User;

class UserStateController extends Controller
{
    public function __construct(
        private readonly ActivateUserAction $activateAction,
        private readonly DeactivateUserAction $deactivateAction,
        private readonly LockUserAction $lockAction,
        private readonly UnlockUserAction $unlockAction,
    ) {}

    public function activate(Request $request, User $user): JsonResponse
    {
        $this->activateAction->run($user);

        activity('user.activated')
            ->causedBy($request->user())
            ->withProperties(['target_id' => $user->id, 'target_email' => $user->email])
            ->log('user.activated');

        return $this->success('User activated successfully.');
    }

    public function deactivate(Request $request, User $user): JsonResponse
    {
        $this->deactivateAction->run($user, $request->user());

        activity('user.deactivated')
            ->causedBy($request->user())
            ->withProperties(['target_id' => $user->id, 'target_email' => $user->email])
            ->log('user.deactivated');

        return $this->success('User deactivated successfully.');
    }

    public function lock(Request $request, User $user): JsonResponse
    {
        $this->lockAction->run($user);

        activity('user.locked')
            ->causedBy($request->user())
            ->withProperties(['target_id' => $user->id, 'target_email' => $user->email])
            ->log('user.locked');

        return $this->success('User locked successfully.');
    }

    public function unlock(Request $request, User $user): JsonResponse
    {
        $this->unlockAction->run($user, $request->ip(), $request);

        activity('user.unlocked')
            ->causedBy($request->user())
            ->withProperties(['target_id' => $user->id, 'target_email' => $user->email])
            ->log('user.unlocked');

        return $this->success('User unlocked successfully.');
    }

    private function success(string $message): JsonResponse
    {
        return response()->json([
            'data' => ['message' => $message],
            'meta' => [
                'request_id' => app('request_id'),
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }
}
