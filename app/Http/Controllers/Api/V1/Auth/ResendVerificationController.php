<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\V1\Auth\ResendVerificationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResendVerificationRequest;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * API auth controller — resend email verification.
 */
class ResendVerificationController extends Controller
{
    /**
     * Resend a verification email to the user.
     *
     * @param  ResendVerificationRequest  $request
     * @param  ResendVerificationAction  $action
     * @return JsonResponse
     */
    public function __invoke(ResendVerificationRequest $request, ResendVerificationAction $action): JsonResponse
    {
        $mode = SystemSetting::getString('email_verification_mode', 'public');

        if ($mode === 'admin' || $mode === 'disabled') {
            return $this->respond('Feature disabled.', 403);
        }

        $email = $request->validated('email');
        $user = User::where('email', $email)->first();

        $result = $action->run($email, $request->ip());

        if (isset($result['error'])) {
            return $this->respond($result['error']['message'], $result['error']['status']);
        }

        if ($user) {
            $user->audit('auth.verification_resent', $request->user());
        }

        return $this->respond('If the email is registered, a verification link has been sent.');
    }
}