<?php

namespace App\Http\Requests\V1\System;

use App\Models\RoleLookup;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates system setting update payloads.
 */
class SystemSettingRequest extends BaseFormRequest
{
    /**
     * Settings are administrator-only.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('settings.manage') ?? false;
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
            'password_require_upper' => ['boolean'],
            'password_require_lower' => ['boolean'],
            'password_require_digit' => ['boolean'],
            'password_require_symbol' => ['boolean'],
            'password_reject_username' => ['boolean'],
            'password_uncompromised' => ['boolean'],
            'password_history_enabled' => ['boolean'],
            'password_history_count' => ['integer', 'min:0', 'max:24'],
            'password_expiry_enabled' => ['boolean'],
            'password_expiry_days' => ['integer', 'min:0', 'max:365'],
            'password_expiry_warn_days' => ['integer', 'min:1', 'max:90'],
            'password_security_sweep_time' => ['nullable', 'date_format:H:i'],
            'password_security_sweep_timezone' => [
                'nullable',
                'string',
                'timezone',
                Rule::exists('timezones', 'name')->where('is_active', true),
            ],
            'inactivity_lock_enabled' => ['boolean'],
            'inactivity_lock_days' => ['integer', 'min:1', 'max:365'],
            'inactivity_lock_grace_enabled' => ['boolean'],
            'inactivity_lock_grace_days' => ['integer', 'min:0', 'max:365'],

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

            // Self-registration
            'registration_enabled' => ['boolean'],
            'registration_rate_limit_per_minute' => ['integer', 'min:1', 'max:30'],
            // Empty means "no role"; otherwise it must name a role this viewer
            // is allowed to pick. That set is the very one the dropdown is built
            // from, so the form cannot offer a choice the rules then refuse, or
            // vice versa — the earlier Rule::exists only asked "does this role
            // exist?", which let a non-superadmin set the default to superadmin
            // by posting it directly and make every self-registrant one.
            'registration_default_role' => [
                'nullable',
                'string',
                Rule::in(RoleLookup::visibleTo($this->user())->pluck('name')),
            ],
        ];
    }

    /**
     * Cast boolean settings from string input to actual booleans before validation.
     */
    protected function prepareForValidation(): void
    {
        foreach (
            [
                'password_require_upper',
                'password_require_lower',
                'password_require_digit',
                'password_require_symbol',
                'password_reject_username',
                'password_uncompromised',
                'password_history_enabled',
                'password_expiry_enabled',
                'inactivity_lock_enabled',
                'inactivity_lock_grace_enabled',
                'allow_username_change',
                'allow_email_change',
                'registration_enabled',
            ] as $key
        ) {
            if ($this->has($key)) {
                $this->merge([$key => $this->boolean($key)]);
            }
        }
    }
}
