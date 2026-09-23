<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Traits\FormatsApiErrors;
use App\Models\SystemSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Shared "change password" validation for Web + API.
 */
class PasswordChangeRequest extends FormRequest
{
    use FormatsApiErrors;

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

    public function currentPassword(): string
    {
        return $this->input('current_password', '');
    }

    public function password(): string
    {
        return $this->input('password', '');
    }
}
