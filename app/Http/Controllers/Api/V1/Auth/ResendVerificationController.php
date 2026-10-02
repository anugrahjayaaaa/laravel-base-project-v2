<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\V1\Auth\AuthResendVerificationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Auth\ResendVerificationRequest;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;

/**
 * API auth controller, resend email verification.
 */
class ResendVerificationController extends Controller
{
    /**
     * Resend a verification email to the user.
     *
     * @param  ResendVerificationRequest  $request
     * @param  AuthResendVerificationAction  $action
     * @return JsonResponse
     */
    public function __invoke(ResendVerificationRequest $request, AuthResendVerificationAction $action): JsonResponse
    {
        $mode = SystemSetting::getString('email_verification_mode', 'public');

        if ($mode === 'admin' || $mode === 'disabled') {
            return $this->respond('Feature disabled.', 403);
        }

        $email = $request->validated('email');

        $result = $action->run($email, $request->ip());

        if (isset($result['error'])) {
            return $this->respond($result['error']['message'], $result['error']['status']);
        }

        // No audit call here. AuthResendVerificationAction writes
        // `auth.verification_resent` itself, on the line after the notification
        // actually went out — and it used to write a second row here, so one
        // resend produced two records, this one outside the action and carrying
        // no properties. The lookup of the User above existed only to have a
        // subject to attach that row to; the action finds the user itself.
        return $this->respond('If the email is registered, a verification link has been sent.');
    }
}