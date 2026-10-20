<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\V1\Auth\AuthVerifyEmailAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiError;
use Dedoc\Scramble\Attributes\Response;
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
     * 403 covers a tampered or expired signed URL; `signed` refuses before this
     * method runs, so the branch cannot be inferred from the body.
     *
     * @param  Request  $request
     * @param  AuthVerifyEmailAction  $action
     * @return JsonResponse
     */
    #[Response(status: 403, type: ApiError::DOC_TYPE)]
    #[Response(status: 422, type: ApiError::DOC_TYPE)]
    public function __invoke(Request $request, AuthVerifyEmailAction $action): JsonResponse
    {
        // Not gated on email_verification_mode: the mode decides who may send a
        // link, not who may use one. See Web\V1\Auth\AuthController::verifyEmail.
        $user = User::findOrFail($request->route('id'));

        $result = $action->run($user);

        if (isset($result['error'])) {
            // The action decides the status; the code is this endpoint's one
            // refusal, and both belong on the documented error body.
            return $this->respondError(
                $result['error']['message'],
                'EMAIL_ALREADY_VERIFIED',
                $result['error']['status'],
            );
        }

        return $this->respond('Email verified successfully.');
    }
}
