<?php

namespace App\Http\Requests\System;

use Illuminate\Foundation\Http\FormRequest;

class SystemSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Login rate limiting & progressive lockout
            'auth_login_max_attempts' => ['integer', 'min:1', 'max:99'],
            'auth_lockout_base_minutes' => ['integer', 'min:1', 'max:60'],
            'auth_lockout_increment_minutes' => ['integer', 'min:1', 'max:120'],
            'auth_login_rate_limit_per_minute' => ['integer', 'min:1', 'max:120'],

            // Password reset / verification rate limits
            'auth_password_forgot_rate_limit' => ['integer', 'min:1', 'max:30'],
            'auth_password_reset_rate_limit' => ['integer', 'min:1', 'max:30'],
            'auth_password_reset_token_expire_minutes' => ['integer', 'min:1', 'max:1440'],
            'auth_email_verification_rate_limit' => ['integer', 'min:1', 'max:100'],
            'auth_email_verification_token_expire_minutes' => ['integer', 'min:1', 'max:1440'],

            // Password policy & lifecycle
            'auth_password_min_length' => ['integer', 'min:4', 'max:128'],
            'auth_password_mixed_case' => ['boolean'],
            'auth_password_numbers' => ['boolean'],
            'auth_password_symbols' => ['boolean'],
            'auth_password_uncompromised' => ['boolean'],
            'auth_password_history_count' => ['integer', 'min:0', 'max:24'],
            'auth_password_expiration_days' => ['integer', 'min:1', 'max:365'],

            // Email verification
            'auth_verification_expire_minutes' => ['integer', 'min:1', 'max:1440'],
            'auth_verification_mode' => ['in:public,admin,disabled'],

            // Password reset token (Laravel broker)
            'auth_password_reset_expire_minutes' => ['integer', 'min:1', 'max:1440'],

            // Username / email change settings
            'allow_username_change' => ['boolean'],
            'username_change_cooldown_days' => ['integer', 'min:0', 'max:365'],
            'allow_email_change' => ['boolean'],
            'email_change_cooldown_days' => ['integer', 'min:0', 'max:365'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['auth_password_mixed_case', 'auth_password_numbers', 'auth_password_symbols', 'auth_password_uncompromised', 'allow_username_change', 'allow_email_change'] as $key) {
            if ($this->has($key)) {
                $this->merge([$key => $this->boolean($key)]);
            }
        }
    }
}