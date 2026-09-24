<?php

namespace App\Http\Requests\System;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates system setting update payloads.
 */
class SystemSettingRequest extends FormRequest
{
    /**
     * Guest route — always authorized.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Define validation rules for all system settings with min/max bounds.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            // Login rate limiting & progressive lockout
            'login_max_attempts' => ['integer', 'min:1', 'max:99'],
            'lockout_base_minutes' => ['integer', 'min:1', 'max:60'],
            'lockout_increment_minutes' => ['integer', 'min:1', 'max:120'],
            'login_rate_limit_per_minute' => ['integer', 'min:1', 'max:120'],

            // Password reset / verification rate limits
            'password_forgot_rate_limit' => ['integer', 'min:1', 'max:30'],
            'password_reset_rate_limit' => ['integer', 'min:1', 'max:30'],
            'password_reset_token_expire_minutes' => ['integer', 'min:1', 'max:1440'],
            'email_verification_rate_limit' => ['integer', 'min:1', 'max:100'],
            'email_verification_token_expire_minutes' => ['integer', 'min:1', 'max:1440'],

            // Password policy & lifecycle
            'password_min_length' => ['integer', 'min:4', 'max:128'],
            'password_mixed_case' => ['boolean'],
            'password_numbers' => ['boolean'],
            'password_symbols' => ['boolean'],
            'password_uncompromised' => ['boolean'],
            'password_history_enabled' => ['boolean'],
            'password_history_count' => ['integer', 'min:0', 'max:24'],
            'password_expiration_days' => ['integer', 'min:1', 'max:365'],

            // Email verification
            'email_verification_expire_minutes' => ['integer', 'min:1', 'max:1440'],
            'email_verification_mode' => ['in:public,admin,disabled'],

            // Password reset token (Laravel broker)
            'password_reset_expire_minutes' => ['integer', 'min:1', 'max:1440'],

            // Username / email change settings
            'allow_username_change' => ['boolean'],
            'username_change_cooldown_days' => ['integer', 'min:0', 'max:365'],
            'allow_email_change' => ['boolean'],
            'email_change_cooldown_days' => ['integer', 'min:0', 'max:365'],
        ];
    }

    /**
     * Cast boolean settings from string input to actual booleans before validation.
     */
    protected function prepareForValidation(): void
    {
        foreach (
            [
                'password_mixed_case',
                'password_numbers',
                'password_symbols',
                'password_uncompromised',
                'password_history_enabled',
                'allow_username_change',
                'allow_email_change'
            ] as $key
        ) {
            if ($this->has($key)) {
                $this->merge([$key => $this->boolean($key)]);
            }
        }
    }
}
