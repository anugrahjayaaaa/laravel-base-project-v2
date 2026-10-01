<?php

namespace App\Http\Requests\V1\Auth;

use App\Http\Requests\Traits\FormatsApiErrors;
use App\Rules\PasswordStrengthRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared "reset password" validation for Web + API.
 */
class PasswordResetRequest extends FormRequest
{
    use FormatsApiErrors;

    /**
     * Authorize: guest route, uses one-time token from URL.
     */
    public function authorize(): bool
    {
        return true; // Guest route,uses a one-time token from the URL.
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => [
                'required',
                'string',
                'confirmed',
                new PasswordStrengthRule(),
            ],
        ];
    }

    /**
     * Get the user's email from the request.
     *
     * @return string
     */
    public function email(): string
    {
        return $this->input('email', '');
    }

    /**
     * Get the user's new password from the request.
     *
     * @return string
     */
    public function password(): string
    {
        return $this->input('password', '');
    }

    /**
     * Get the reset token from the request or route parameter.
     *
     * @return string
     */
    public function token(): string
    {
        return $this->input('token', $this->route('token', ''));
    }
}
