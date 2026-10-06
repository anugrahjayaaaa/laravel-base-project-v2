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
        SystemSetting::set('login_max_attempts', '5');
        SystemSetting::set('lockout_base_minutes', '5');
        SystemSetting::set('lockout_increment_minutes', '10');
        SystemSetting::set('login_rate_limit_per_minute', '5');

        // Password reset / verification rate limits
        SystemSetting::set('password_forgot_rate_limit', '3');
        SystemSetting::set('password_reset_rate_limit', '3');
        SystemSetting::set('password_reset_token_expire_minutes', '15');
        SystemSetting::set('email_verification_rate_limit', '5');
        SystemSetting::set('email_verification_token_expire_minutes', '60');

        // Password policy (IM8,admin-editable via /settings)
        SystemSetting::set('password_min_length', '12');
        SystemSetting::set('password_require_upper', 'true');
        SystemSetting::set('password_require_lower', 'true');
        SystemSetting::set('password_require_digit', 'true');
        SystemSetting::set('password_require_symbol', 'true');
        SystemSetting::set('password_reject_username', 'true');

        // Password lifecycle
        SystemSetting::set('password_history_enabled', 'true');
        SystemSetting::set('password_history_count', '5');
        SystemSetting::set('password_expiry_enabled', 'true');
        SystemSetting::set('password_expiry_days', '90');
        SystemSetting::set('password_expiry_warn_days', '14');
        SystemSetting::set('password_security_sweep_time', '00:00');
        SystemSetting::set('password_security_sweep_timezone', '');
        SystemSetting::set('inactivity_lock_enabled', 'true');
        SystemSetting::set('inactivity_lock_days', '30');
        SystemSetting::set('inactivity_lock_grace_enabled', 'true');
        SystemSetting::set('inactivity_lock_grace_days', '30');

        // Email verification
        SystemSetting::set('email_verification_expire_minutes', '60');
        SystemSetting::set('email_verification_mode', 'public');

        // Password reset token (Laravel broker)
        SystemSetting::set('password_reset_expire_minutes', '15');

        // Username / email change settings
        SystemSetting::set('allow_username_change', 'true');
        SystemSetting::set('username_change_cooldown_days', '30');
        SystemSetting::set('allow_email_change', 'true');
        SystemSetting::set('email_change_cooldown_days', '30');

        // Self-registration. Off by default: a base project that ships with a
        // public sign-up form has one on every install until someone notices.
        SystemSetting::set('registration_enabled', 'false');
        SystemSetting::set('registration_default_role', 'user');
        SystemSetting::set('registration_rate_limit_per_minute', '3');

        // Mail transport — Phase 9 Group B.
        //
        // Seeded from the .env values this app already boots with, so an install
        // that never opens the notifications page keeps working exactly as
        // before: `bindMailConfig()` reads each of these with the config value as
        // its fallback, and these rows simply make the same answer explicit.
        //
        // `mail_password` is the exception and stays EMPTY here. Seeding it from
        // the .env would write a credential into the settings table on every
        // install, and the first `db:seed` on a developer's machine would copy a
        // live password into a row anyone holding `notifications.view` can read.
        // An empty value means "keep using the .env credential".
        SystemSetting::set('mail_mailer', config('mail.default', 'log'));
        SystemSetting::set('mail_host', (string) config('mail.mailers.smtp.host', '127.0.0.1'));
        SystemSetting::set('mail_port', (string) config('mail.mailers.smtp.port', 2525));
        SystemSetting::set('mail_username', (string) config('mail.mailers.smtp.username', ''));
        SystemSetting::set('mail_password', '');
        SystemSetting::set('mail_encryption', (string) (config('mail.mailers.smtp.scheme') ?: ''));
        SystemSetting::set('mail_from_address', (string) config('mail.from.address', ''));
        SystemSetting::set('mail_from_name', (string) config('mail.from.name', ''));

        // Notification delivery channels — Phase 9 Group B (P9-B7).
        //
        // Global and admin-owned, not per user. Notification delivery is
        // per-recipient by definition (a registration event reaches admins, a
        // password expiry reaches one account), so a per-user preference screen
        // would describe a choice nobody needs to make.
        //
        // `in_app` gates the inbox alongside `database` — they describe the same
        // thing (rows written to the notifications table), so a split pair where
        // only one is read would be a control that saves and changes nothing.
        // Seeded ON so the two agree on a fresh install; the badge and the rows
        // exist together or neither is useful.
        SystemSetting::set('notification_channel_in_app', 'true');

        SystemSetting::set('notification_channel_mail', 'true');
        // On by default now that the `notifications` table exists. Off would
        // mean a fresh install has an inbox, a bell and a table, and nothing
        // ever arrives in any of them.
        SystemSetting::set('notification_channel_database', 'true');
    }
}
