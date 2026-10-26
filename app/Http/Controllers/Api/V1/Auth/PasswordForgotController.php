<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\V1\Auth\AuthSendResetLinkAction;
use App\Auth\LoginThrottle;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Auth\PasswordForgotRequest;
use Illuminate\Http\JsonResponse;

/**
 * API auth controller, send password reset link.
 */
class PasswordForgotController extends Controller
{
    /**
     * Send a password reset link to the given email.
     *
     * @param  PasswordForgotRequest  $request
     * @param  LoginThrottle  $throttle
     * @param  AuthSendResetLinkAction  $action
     * @return JsonResponse
     */
    public function __invoke(
        PasswordForgotRequest $request,
        LoginThrottle $throttle,
        AuthSendResetLinkAction $action,
    ): JsonResponse {
        $data = $request->validated();
        $email = $data['email'];
        $ip = $request->ip();

        // One line, no branch on the result: whether the address exists is not
        // the caller's business (enumeration), so a refusal and a send have to
        // look identical from outside. The action still refuses internally.
        $action->run($email, $ip, $request, $throttle);

        return $this->respond('If the email exists, a reset link has been sent.');
    }
}
