<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Traits\FormatsApiErrors;
use App\Models\SystemSetting;
use App\Rules\PasswordStrengthRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

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
