<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Actions\V1\User\UserActivateAction;
use App\Actions\V1\User\UserDeactivateAction;
use App\Actions\V1\User\UserLockAction;
use App\Actions\V1\User\UserUnlockAction;
use App\Http\Controllers\Controller;
use App\Support\ApiError;
use Dedoc\Scramble\Attributes\Response;
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

        return $this->respond('User activated successfully.');
    }

    /**
     * Deactivate a user.
     *
     * 409 `LAST_SUPERADMIN` when this row is the last active superadmin;
     * `UserDeactivateAction` raises it inside its transaction.
     *
     * @param  Request  $request
     * @param  User  $user
     * @return JsonResponse
     */
    #[Response(status: 409, type: ApiError::DOC_TYPE)]
    public function deactivate(Request $request, User $user): JsonResponse
    {
        $this->deactivateAction->run($user, $request->user());

        return $this->respond('User deactivated successfully.');
    }

    /**
     * Lock a user account.
     *
     * 409 `LAST_SUPERADMIN` when this row is the last active superadmin; a
     * locked superadmin cannot log in either, so `UserLockAction` asks the
     * same question `deactivate` and `delete` do.
     *
     * @param  Request  $request
     * @param  User  $user
     * @return JsonResponse
     */
    #[Response(status: 409, type: ApiError::DOC_TYPE)]
    public function lock(Request $request, User $user): JsonResponse
    {
        $this->lockAction->run($user, $request->user());

        return $this->respond('User locked successfully.');
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

        return $this->respond('User unlocked successfully.');
    }
}
