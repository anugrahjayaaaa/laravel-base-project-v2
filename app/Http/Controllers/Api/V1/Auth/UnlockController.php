<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\User\UnlockUserAction;
use App\Auth\LoginThrottle;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Spatie\Activitylog\Facades\Activity;

class UnlockController extends Controller
{
    public function __invoke(
        Request $request,
        User $user,
        LoginThrottle $throttle,
        UnlockUserAction $action,
    ): JsonResponse {
        $action->run($user, $request->ip(), $request, $throttle);

        activity('auth.user_unlocked')
            ->causedBy($request->user())
            ->withProperties([
                'target_id' => $user->id,
                'target_username' => $user->username,
                'target_email' => $user->email
            ])
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
