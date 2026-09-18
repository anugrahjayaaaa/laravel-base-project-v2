<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Auth\LoginThrottle;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class LoginController extends Controller
{
    public function __invoke(
        LoginRequest $request,
        LoginThrottle $throttle,
    ): JsonResponse {
        $identifier = $request->input('identifier');
        $password = $request->input('password');
        $ip = $request->ip();

        // Support email OR username lookup.
        $user = User::where('email', $identifier)
            ->orWhere('username', $identifier)
            ->first();

        $error = null;
        $status = 401;

        if (! $user || ! Hash::check($password, $user->password)) {
            $throttle->recordFailed($identifier, $ip);
            $error = 'Invalid credentials.';
            $status = 401;
        } elseif (! $user->is_active) {
            $error = 'Account is inactive.';
            $status = 403;
        } elseif ($user->is_locked) {
            $lockedFor = $throttle->lockedFor($identifier, $ip);
            $minutes = (int) ceil(max($lockedFor, 0) / 60);
            $error = "Account is locked. Try again in {$minutes} minute(s).";
            $status = 403;
        }

        if ($error) {
            return $this->respond($error, $status);
        }

        // Success: reset throttle, update activity, issue token.
        $throttle->reset($identifier, $ip);

        $user->updateQuietly(['last_activity_at' => now()]);

        $token = $user->createToken('auth-token')->plainTextToken;

        $this->audit('auth.login', $user, $user, [
            'ip' => $ip,
            'user_agent' => $request->userAgent(),
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