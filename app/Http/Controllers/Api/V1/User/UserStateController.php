<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Actions\User\ActivateUserAction;
use App\Actions\User\DeactivateUserAction;
use App\Actions\User\LockUserAction;
use App\Actions\User\UnlockUserAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
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

        $user->audit('user.activated', $request->user(), ['target_id' => $user->id, 'target_email' => $user->email]);

        return $this->success('User activated successfully.');
    }

    public function deactivate(Request $request, User $user): JsonResponse
    {
        $this->deactivateAction->run($user, $request->user());

        $user->audit('user.deactivated', $request->user(), ['target_id' => $user->id, 'target_email' => $user->email]);

        return $this->success('User deactivated successfully.');
    }

    public function lock(Request $request, User $user): JsonResponse
    {
        $this->lockAction->run($user);

        $user->audit('user.locked', $request->user(), ['target_id' => $user->id, 'target_email' => $user->email]);

        return $this->success('User locked successfully.');
    }

    public function unlock(Request $request, User $user): JsonResponse
    {
        $this->unlockAction->run($user, $request->ip(), $request);

        $user->audit('user.unlocked', $request->user(), ['target_id' => $user->id, 'target_email' => $user->email]);

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
