<?php

namespace App\Actions\V1\System;

use App\Models\SystemSetting;

/**
 * Persist validated system settings using the shared web/API mapping.
 *
 * The controllers remain responsible for request validation, audit logging,
 * and response formatting. This action owns only setting normalization and
 * persistence so both channels cannot drift.
 */
class UpdateSystemSettingsAction
{
    /**
     * Normalize and persist the settings payload.
     *
     * Missing fields intentionally use the historical defaults, matching the
     * settings form contract and the former controller implementation.
     *
     * @param array<string, mixed> $data
     */
    public function run(array $data): void
    {
        $updates = [
            // Login rate limiting & progressive lockout
            'login_max_attempts' => (string) ($data['login_max_attempts'] ?? 5),
            'lockout_base_minutes' => (string) ($data['lockout_base_minutes'] ?? 5),
            'lockout_increment_minutes' => (string) ($data['lockout_increment_minutes'] ?? 10),
            'login_rate_limit_per_minute' => (string) ($data['login_rate_limit_per_minute'] ?? 5),

            // Password reset / verification rate limits
            'password_forgot_rate_limit' => (string) ($data['password_forgot_rate_limit'] ?? 3),
            'password_reset_rate_limit' => (string) ($data['password_reset_rate_limit'] ?? 3),
            'password_reset_token_expire_minutes' => (string) ($data['password_reset_token_expire_minutes'] ?? 15),
            'email_verification_rate_limit' => (string) ($data['email_verification_rate_limit'] ?? 5),
            'email_verification_token_expire_minutes' => (string) ($data['email_verification_token_expire_minutes'] ?? 60),

            // Password policy & lifecycle
            'password_min_length' => (string) ($data['password_min_length'] ?? 8),
            'password_mixed_case' => ($data['password_mixed_case'] ?? false) ? 'true' : 'false',
            'password_numbers' => ($data['password_numbers'] ?? false) ? 'true' : 'false',
            'password_symbols' => ($data['password_symbols'] ?? false) ? 'true' : 'false',
            'password_uncompromised' => ($data['password_uncompromised'] ?? false) ? 'true' : 'false',
            'password_history_enabled' => ($data['password_history_enabled'] ?? false) ? 'true' : 'false',
            'password_history_count' => (string) ($data['password_history_count'] ?? 5),
            'password_expiration_days' => (string) ($data['password_expiration_days'] ?? 90),
            'password_expiry_enabled' => ($data['password_expiry_enabled'] ?? false) ? 'true' : 'false',
            'password_expiry_days' => (string) ($data['password_expiry_days'] ?? 90),
            'password_expiry_warn_days' => (string) ($data['password_expiry_warn_days'] ?? 14),
            'password_security_sweep_time' => $data['password_security_sweep_time'] ?? '00:00',
            'password_security_sweep_timezone' => $data['password_security_sweep_timezone'] ?? '',
            'inactivity_lock_enabled' => ($data['inactivity_lock_enabled'] ?? false) ? 'true' : 'false',
            'inactivity_lock_days' => (string) ($data['inactivity_lock_days'] ?? 30),

            // Email verification
            'email_verification_expire_minutes' => (string) ($data['email_verification_expire_minutes'] ?? 60),
            'email_verification_mode' => $data['email_verification_mode'] ?? 'public',

            // Password reset token (Laravel broker)
            'password_reset_expire_minutes' => (string) ($data['password_reset_expire_minutes'] ?? 15),

            // Username / email change settings
            'allow_username_change' => ($data['allow_username_change'] ?? false) ? 'true' : 'false',
            'username_change_cooldown_days' => (string) ($data['username_change_cooldown_days'] ?? 30),
            'allow_email_change' => ($data['allow_email_change'] ?? false) ? 'true' : 'false',
            'email_change_cooldown_days' => (string) ($data['email_change_cooldown_days'] ?? 30),
        ];

        foreach ($updates as $key => $value) {
            SystemSetting::set($key, $value);
        }
    }
}
