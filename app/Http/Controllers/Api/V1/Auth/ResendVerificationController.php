<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\ResendVerificationAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResendVerificationController extends Controller
{
    public function __invoke(Request $request, ResendVerificationAction $action): JsonResponse
    {
        $mode = config('auth.verification.mode', 'public');

        if ($mode === 'admin' || $mode === 'disabled') {
            return $this->respond('Feature disabled.', 403);
        }

        $result = $action->run($request->input('email'), $request->ip());

        if (isset($result['error'])) {
            return $this->respond($result['error']['message'], $result['error']['status']);
        }

        return $this->respond('If the email is registered, a verification link has been sent.');
    }
}