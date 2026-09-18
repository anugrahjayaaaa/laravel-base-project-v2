<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VerifyEmailController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = User::findOrFail($request->route('id'));

        if ($user->hasVerifiedEmail()) {
            return $this->respond('Email already verified.', 422);
        }

        $user->markEmailAsVerified();

        $this->audit('auth.email_verified', $user, $user);

        return $this->respond('Email verified successfully.');
    }
}
