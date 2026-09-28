<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Traits\FormatsApiErrors;
use App\Rules\PasswordStrengthRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared "change password" validation for Web + API.
 */
class PasswordChangeRequest extends FormRequest
{
    use FormatsApiErrors;

    /**
     * Must be authenticated to change own password.
     */
    public function authorize(): bool
    {
        return auth()->check(); // Must be authenticated.
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
            'password' => [
                'required',
                'string',
                'confirmed',
                new PasswordStrengthRule(),
            ],
        ];
    }

    /**
     * Get the current password from the request.
     *
     * @return string
     */
    public function currentPassword(): string
    {
        return $this->input('current_password', '');
    }

    /**
     * Get the new password from the request.
     *
     * @return string
     */
    public function password(): string
    {
        return $this->input('password', '');
    }
}
