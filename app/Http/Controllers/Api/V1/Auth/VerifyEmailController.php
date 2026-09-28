<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\V1\Auth\VerifyEmailAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API auth controller, verify email.
 */
class VerifyEmailController extends Controller
{
    /**
     * Verify the user's email address.
     *
     * @param  Request  $request
     * @param  VerifyEmailAction  $action
     * @return JsonResponse
     */
    public function __invoke(Request $request, VerifyEmailAction $action): JsonResponse
    {
        // Not gated on email_verification_mode: the mode decides who may send a
        // link, not who may use one. See Web\V1\Auth\AuthController::verifyEmail.
        $user = User::findOrFail($request->route('id'));

        $result = $action->run($user);

        if (isset($result['error'])) {
            return $this->respond($result['error']['message'], $result['error']['status']);
        }

        $this->audit('auth.email_verified', $result['user'], $result['user']);

        return $this->respond('Email verified successfully.');
    }
}
