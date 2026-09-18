<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\PasswordForgotRequest;
use App\Auth\LoginThrottle;
use App\Traits\Auth\HandlesUserLookup;
use App\Traits\Auth\HandlesLockCheck;
use App\Traits\Auth\HandlesPasswordResetFlow;
use Illuminate\Http\JsonResponse;

class PasswordForgotController extends Controller
{
    use HandlesUserLookup;
    use HandlesLockCheck;
    use HandlesPasswordResetFlow;

    public function __invoke(
        PasswordForgotRequest $request,
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
                'message' => 'If the email exists, a reset link has been sent.',
                'code' => 'ACCOUNT_LOCKED',
            ], 403);
        }

        $this->sendResetLink($email, $user, $request);

        return response()->json([
            'data' => ['message' => 'If the email exists, a reset link has been sent.'],
            'meta' => [
                'request_id' => app('request_id'),
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }
}
