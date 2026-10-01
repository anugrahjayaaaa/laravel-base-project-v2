<?php

namespace App\Http\Requests\V1\Auth;

use App\Http\Requests\BaseFormRequest;

/**
 * Shared "forgot password" validation for Web + API.
 */
class PasswordForgotRequest extends BaseFormRequest
{
    /**
     * Guest route,always authorized.
     */
    public function authorize(): bool
    {
        return true; // Guest route.
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
        ];
    }

    /**
     * Get the email address for the password reset link.
     *
     * @return string
     */
    public function email(): string
    {
        return $this->input('email', '');
    }
}
