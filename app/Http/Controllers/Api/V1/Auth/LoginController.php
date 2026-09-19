<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Traits\Auth\AuthenticatesUsers;
use App\Auth\LoginThrottle;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class LoginController extends Controller
{
    use AuthenticatesUsers;

    public function __invoke(
        LoginRequest $request,
        LoginThrottle $throttle,
    ): JsonResponse {
        $identifier = $request->input('identifier');
        $ip = $request->ip();

        $throttleError = $this->checkThrottle($identifier, $ip, $throttle);
        if ($throttleError) {
            return $this->respond($throttleError['message'], $throttleError['status']);
        }

        $user = $this->findUser($identifier, $request->input('password'));

        if (! $user) {
            $lockedSeconds = $throttle->recordFailed($identifier, $ip);
            $this->audit('auth.login_failed', null, null, [
                'identifier' => $identifier,
                'ip' => $ip,
                'user_agent' => $request->userAgent(),
                'channel' => 'api',
            ]);
            if ($lockedSeconds > 0) {
                $this->audit('auth.account_locked', null, null, [
                    'identifier' => $identifier,
                    'ip' => $ip,
                    'user_agent' => $request->userAgent(),
                    'channel' => 'api',
                    'lock_duration_seconds' => $lockedSeconds,
                ]);
            }

            return $this->respond('Invalid credentials.', 401);
        }

        $accountError = $this->checkAccountState($user);

        if ($accountError) {
            if ($user->is_locked) {
                $accountError['message'] = 'Account is locked by administrator.';
            }
            $this->audit('auth.login_failed', $user, $user, [
                'identifier' => $identifier,
                'ip' => $ip,
                'user_agent' => $request->userAgent(),
                'channel' => 'api',
            ]);

            return $this->respond($accountError['message'], $accountError['status']);
        }

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
