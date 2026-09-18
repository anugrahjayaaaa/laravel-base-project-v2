<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\PasswordResetRequest;
use App\Auth\LoginThrottle;
use App\Traits\Auth\HandlesUserLookup;
use App\Traits\Auth\HandlesLockCheck;
use App\Traits\Auth\HandlesPasswordResetFlow;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;

class PasswordResetController extends Controller
{
    use HandlesUserLookup;
    use HandlesLockCheck;
    use HandlesPasswordResetFlow;

    public function __invoke(
        PasswordResetRequest $request,
        LoginThrottle $throttle,
    ): JsonResponse {
        $email = $request->input('email');
        $user = $this->lookupUser($email);

        $lockError = $this->checkUserLock($user, $request->ip(), $throttle);
        if ($lockError) {
            $this->audit('auth.password_reset_requested', $user, $user, [
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Account is locked. Reset password unavailable.',
                'code' => 'ACCOUNT_LOCKED',
            ], 403);
        }

        $status = $this->resetPassword($request, $user);
        $this->auditPasswordResetCompleted($status, $user, $request);

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'data' => ['message' => 'Password reset successfully.'],
                'meta' => [
                    'request_id' => app('request_id'),
                    'timestamp' => now()->toIso8601String(),
                ],
            ]);
        }

        return response()->json([
            'message' => 'Invalid or expired token.',
            'code' => 'RESET_TOKEN_INVALID',
        ], 400);
    }
}
