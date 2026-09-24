<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Traits\FormatsApiErrors;
use App\Models\SystemSetting;
use App\Rules\PasswordStrengthRule;
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
                new PasswordStrengthRule(),
            ],
        ];
    }

    /**
     * Build the IM8 password validation rule from settings.
     *
     * @deprecated Use PasswordStrengthRule instead. Kept for reference/fallback.
     */
    protected function passwordRule(): Password
    {
        $rule = Password::min(SystemSetting::getInt('password_min_length', 12));

        if (SystemSetting::getBool('password_require_upper', true)) {
            $rule->mixedCase();
        }

        if (SystemSetting::getBool('password_require_lower', true)) {
            $rule->letters();
        }

        if (SystemSetting::getBool('password_require_digit', true)) {
            $rule->numbers();
        }

        if (SystemSetting::getBool('password_require_symbol', true)) {
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
