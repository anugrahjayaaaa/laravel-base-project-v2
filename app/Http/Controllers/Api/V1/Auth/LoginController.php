<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\V1\Auth\AuthAuthenticateAction;
use App\Actions\V1\Auth\AuthLoginCompletedAction;
use App\Auth\LoginThrottle;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Auth\LoginRequest;
use Illuminate\Http\JsonResponse;

/**
 * API auth controller, login, returns JSON responses.
 */
class LoginController extends Controller
{
    /**
     * Authenticate user and return access token.
     *
     * @param  LoginRequest  $request
     * @param  LoginThrottle  $throttle
     * @param  AuthAuthenticateAction  $action
     * @param  AuthLoginCompletedAction  $loginCompletedAction
     * @return JsonResponse
     */
    public function __invoke(
        LoginRequest $request,
        LoginThrottle $throttle,
        AuthAuthenticateAction $action,
        AuthLoginCompletedAction $loginCompletedAction,
    ): JsonResponse {
        $data = $request->validated();
        $identifier = $data['identifier'];
        $ip = $request->ip();

        $result = $action->run($identifier, $data['password'], $ip, $throttle);

        if (isset($result['error'])) {
            if (($result['error']['error_code'] ?? null) === 'UNVERIFIED_EMAIL') {
                return $this->respondError(
                    'Email not verified',
                    'UNVERIFIED_EMAIL',
                    403,
                    [
                        'email' => $identifier,
                        'verified' => false,
                    ]
                );
            }

            if (isset($result['lockedSeconds']) && $result['lockedSeconds'] > 0) {
                return $this->respondError(
                    $result['error']['message'],
                    'RATE_LIMITED',
                    429,
                    [
                        'retry_after_seconds' => $result['lockedSeconds'],
                    ]
                );
            }

            $status = $result['error']['status'];

            return $this->respondError(
                $result['error']['message'],
                $status === 403 ? 'FORBIDDEN' : 'UNAUTHENTICATED',
                $status,
            );
        }

        $user = $result['user'];
        $throttle->reset($identifier, $ip);

        $token = $loginCompletedAction->run($user, $request->boolean('remember'));

        return $this->respond('Login successful.', 200, [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified' => $user->hasVerifiedEmail(),
            ],
        ]);
    }
}
