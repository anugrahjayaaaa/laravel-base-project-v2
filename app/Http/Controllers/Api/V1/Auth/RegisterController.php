<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\V1\User\UserCreateAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;

/**
 * API auth controller, self-registration.
 *
 * Same action as the web form. No session or token is issued: the account is
 * unverified, and the caller has to use the emailed link before either the web
 * or API login will accept it.
 */
class RegisterController extends Controller
{
    /**
     * Create a self-registered account and email a verification link.
     *
     * @param  RegisterRequest  $request
     * @param  UserCreateAction  $action
     * @return JsonResponse
     */
    public function __invoke(RegisterRequest $request, UserCreateAction $action): JsonResponse
    {
        // A disabled feature is a 404 on the web, where the route has no meaning
        // at all. An API client gets the same answer so it cannot probe for the
        // existence of a feature the operator turned off.
        if (! SystemSetting::getBool('registration_enabled', false)) {
            return $this->respond('Not found.', 404);
        }

        $data = $request->validated();
        $user = $action->run($data, $request->password());

        $user->audit('user.registered', $request->user(), [
            'ip' => $request->ip(),
            'channel' => 'api',
        ]);

        return $this->respond('Account created. Check your email to verify it before logging in.', 201, [
            'id' => $user->getKey(),
            'username' => $user->username,
            'email' => $user->email,
        ]);
    }
}
