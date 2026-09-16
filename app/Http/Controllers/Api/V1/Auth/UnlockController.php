<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Auth\LoginThrottle;
use App\Http\Requests\Auth\UnlockUserRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Facades\Activity;

class UnlockController
{
    public function __invoke(
        UnlockUserRequest $request,
        User $user,
        LoginThrottle $throttle,
    ): JsonResponse {
        DB::transaction(function () use ($user, $throttle, $request) {
            $user->update(['is_locked' => false]);

            // Clear failed-login escalation state for this user.
            $throttle->reset($user->email, $request->ip());
        });

        activity('auth.user_unlocked')
            ->causedBy($request->user())
            ->withProperties(['target_id' => $user->id, 'target_email' => $user->email])
            ->log('auth.user_unlocked');

        return response()->json([
            'data' => ['message' => 'User unlocked successfully.'],
            'meta' => [
                'request_id' => app('request_id'),
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }
}
