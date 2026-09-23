<?php

namespace Database\Seeders;

use App\Models\SystemSetting;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Seeds system settings with defaults matching config/rate_limits.php
 * and config/auth.php values.
 *
 * Run after fresh migration to populate initial configuration.
 * Existing rows are not overwritten (updateOrCreate).
 */
class SystemSettingSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Login rate limiting & progressive lockout
        SystemSetting::set('auth_login_max_attempts', '5');
        SystemSetting::set('auth_lockout_base_minutes', '5');
        SystemSetting::set('auth_lockout_increment_minutes', '10');
        SystemSetting::set('auth_login_rate_limit_per_minute', '5');

        // Password reset / verification rate limits
        SystemSetting::set('auth_password_forgot_rate_limit', '3');
        SystemSetting::set('auth_password_reset_rate_limit', '3');
        SystemSetting::set('auth_password_reset_token_expire_minutes', '15');
        SystemSetting::set('auth_email_verification_rate_limit', '5');
        SystemSetting::set('auth_email_verification_token_expire_minutes', '60');

        // Password policy & lifecycle
        SystemSetting::set('auth_password_min_length', '8');
        SystemSetting::set('auth_password_mixed_case', 'true');
        SystemSetting::set('auth_password_numbers', 'true');
        SystemSetting::set('auth_password_symbols', 'true');
        SystemSetting::set('auth_password_uncompromised', 'false');
        SystemSetting::set('auth_password_history_count', '5');
        SystemSetting::set('auth_password_expiration_days', '90');

        // Email verification
        SystemSetting::set('auth_verification_expire_minutes', '60');
        SystemSetting::set('auth_verification_mode', 'public');

        // Password reset token (Laravel broker)
        SystemSetting::set('auth_password_reset_expire_minutes', '15');

        // Username / email change settings
        SystemSetting::set('allow_username_change', 'true');
        SystemSetting::set('username_change_cooldown_days', '30');
        SystemSetting::set('allow_email_change', 'true');
        SystemSetting::set('email_change_cooldown_days', '30');
    }
}