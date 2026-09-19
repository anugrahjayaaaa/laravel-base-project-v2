<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\AuthenticateUserAction;
use App\Auth\LoginThrottle;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class LoginController extends Controller
{
    public function __invoke(
        LoginRequest $request,
        LoginThrottle $throttle,
        AuthenticateUserAction $action,
    ): JsonResponse {
        $data = $request->validated();
        $identifier = $data['identifier'];
        $ip = $request->ip();

        $result = $action->run($identifier, $data['password'], $ip, $throttle);

        $user = User::where('email', $identifier)->orWhere('username', $identifier)->first();

        if (isset($result['error'])) {
            if (isset($result['lockedSeconds']) && $result['lockedSeconds'] > 0) {
                $this->audit('auth.login_failed', $user, $user, [
                    'identifier' => $identifier,
                    'ip' => $ip,
                    'user_agent' => $request->userAgent(),
                    'channel' => 'api',
                ]);

                $this->audit('auth.account_locked', $result['user'], $result['user'], [
                    'identifier' => $identifier,
                    'ip' => $ip,
                    'user_agent' => $request->userAgent(),
                    'channel' => 'api',
                    'lock_duration_seconds' => $result['lockedSeconds'],
                ]);
            } else {
                $this->audit('auth.login_failed', $user, $user, [
                    'identifier' => $identifier,
                    'ip' => $ip,
                    'user_agent' => $request->userAgent(),
                    'channel' => 'api',
                ]);
            }

            if (($result['error']['error_code'] ?? null) === 'UNVERIFIED_EMAIL') {
                return response()->json([
                    'message' => 'Email not verified',
                    'email' => $identifier,
                    'verified' => false,
                ], 403);
            }

            return $this->respond($result['error']['message'], $result['error']['status']);
        }

        $user = $result['user'];
        $throttle->reset($identifier, $ip);
        $user->updateQuietly(['last_activity_at' => now()]);
        $token = $user->createToken('auth-token')->plainTextToken;

        $this->audit('auth.login', $user, $user, [
            'ip' => $ip,
            'user_agent' => $request->userAgent(),
            'channel' => 'api',
        ]);

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
