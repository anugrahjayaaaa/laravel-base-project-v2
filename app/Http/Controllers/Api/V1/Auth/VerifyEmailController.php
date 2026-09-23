<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\V1\Auth\VerifyEmailAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VerifyEmailController extends Controller
{
    public function __invoke(Request $request, VerifyEmailAction $action): JsonResponse
    {
        $mode = config('auth.verification.mode', 'public');

        if ($mode === 'admin' || $mode === 'disabled') {
            return $this->respond('Feature disabled.', 403);
        }

        $user = User::findOrFail($request->route('id'));

        $result = $action->run($user);

        if (isset($result['error'])) {
            return $this->respond($result['error']['message'], $result['error']['status']);
        }

        $this->audit('auth.email_verified', $result['user'], $result['user']);

        return $this->respond('Email verified successfully.');
    }
}
