<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Actions\V1\User\UserActivateAction;
use App\Actions\V1\User\UserDeactivateAction;
use App\Actions\V1\User\UserLockAction;
use App\Actions\V1\User\UserUnlockAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\User;

/**
 * API user state controller, activate, deactivate, lock, unlock users.
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
     * @param  Request  $request
     * @param  User  $user
     * @return JsonResponse
     */
    public function activate(Request $request, User $user): JsonResponse
    {
        $this->activateAction->run($user, $request->user());

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
        $this->lockAction->run($user, $request->user());

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
        $this->unlockAction->run($user, $request->user(), request()->ip(), request());

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
