<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Traits\FormatsApiErrors;
use App\Models\SystemSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

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
        return true; // Guest route — uses a one-time token from the URL.
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
                $this->passwordRule(),
            ],
        ];
    }

    /**
     * Build the IM8 password validation rule from config.
     */
    protected function passwordRule(): Password
    {
        $rule = Password::min(SystemSetting::getInt('auth_password_min_length', 8));

        if (SystemSetting::getBool('auth_password_mixed_case', true)) {
            $rule->mixedCase();
        }
        if (SystemSetting::getBool('auth_password_numbers', true)) {
            $rule->numbers();
        }
        if (SystemSetting::getBool('auth_password_symbols', true)) {
            $rule->symbols();
        }

        return $rule;
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
