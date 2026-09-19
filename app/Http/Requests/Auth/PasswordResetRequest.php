<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Traits\FormatsApiErrors;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Shared "reset password" validation for Web + API.
 */
class PasswordResetRequest extends FormRequest
{
    use FormatsApiErrors;

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
        $rule = Password::min((int) config('rate_limits.password_policy.min', 8));

        if (config('rate_limits.password_policy.mixed_case', true)) {
            $rule->mixedCase();
        }
        if (config('rate_limits.password_policy.numbers', true)) {
            $rule->numbers();
        }
        if (config('rate_limits.password_policy.symbols', true)) {
            $rule->symbols();
        }

        return $rule;
    }

    public function email(): string
    {
        return $this->input('email', '');
    }

    public function password(): string
    {
        return $this->input('password', '');
    }

    public function token(): string
    {
        return $this->input('token', $this->route('token', ''));
    }
}
