<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Actions\V1\User\ActivateUserAction;
use App\Actions\V1\User\DeactivateUserAction;
use App\Actions\V1\User\LockUserAction;
use App\Actions\V1\User\UnlockUserAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\User;

/**
 * API user state controller — activate, deactivate, lock, unlock users.
 */
class UserStateController extends Controller
{
    /**
     * @param  ActivateUserAction  $activateAction
     * @param  DeactivateUserAction  $deactivateAction
     * @param  LockUserAction  $lockAction
     * @param  UnlockUserAction  $unlockAction
     */
    public function __construct(
        private readonly ActivateUserAction $activateAction,
        private readonly DeactivateUserAction $deactivateAction,
        private readonly LockUserAction $lockAction,
        private readonly UnlockUserAction $unlockAction,
    ) {}

    /**
     * Activate a user.
     *
     * @param  Request  $request
     * @param  User  $user
     * @return JsonResponse
     */
    public function activate(Request $request, User $user): JsonResponse
    {
        $this->activateAction->run($user);

        $user->audit('user.activated', $request->user(), ['target_id' => $user->id, 'target_email' => $user->email]);

        return $this->success('User activated successfully.');
    }

    /**
     * Deactivate a user.
     *
     * @param  Request  $request
     * @param  User  $user
     * @return JsonResponse
     */
    public function deactivate(Request $request, User $user): JsonResponse
    {
        $this->deactivateAction->run($user, $request->user());

        $user->audit('user.deactivated', $request->user(), ['target_id' => $user->id, 'target_email' => $user->email]);

        return $this->success('User deactivated successfully.');
    }

    /**
     * Lock a user account.
     *
     * @param  Request  $request
     * @param  User  $user
     * @return JsonResponse
     */
    public function lock(Request $request, User $user): JsonResponse
    {
        $this->lockAction->run($user);

        $user->audit('user.locked', $request->user(), ['target_id' => $user->id, 'target_email' => $user->email]);

        return $this->success('User locked successfully.');
    }

    /**
     * Unlock a user account.
     *
     * @param  Request  $request
     * @param  User  $user
     * @return JsonResponse
     */
    public function unlock(Request $request, User $user): JsonResponse
    {
        $this->unlockAction->run($user, $request->ip(), $request);

        $user->audit('user.unlocked', $request->user(), ['target_id' => $user->id, 'target_email' => $user->email]);

        return $this->success('User unlocked successfully.');
    }

    /**
     * Helper: return a standardized success JSON response.
     *
     * @param  string  $message
     * @return JsonResponse
     */
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
