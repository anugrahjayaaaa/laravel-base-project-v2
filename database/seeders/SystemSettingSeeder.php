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
        SystemSetting::set('login_ip_rate_limit_per_minute', '10');

        // Password reset / verification rate limits
        SystemSetting::set('password_forgot_rate_limit', '3');
        SystemSetting::set('password_reset_rate_limit', '3');
        SystemSetting::set('password_change_rate_limit_per_minute', '5');
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

        // API token lifetime — one day. Stored as a number plus a unit so the
        // settings page can offer minutes, hours or days; `AppServiceProvider`
        // converts the pair to the minutes Sanctum counts in. One day is the
        // default because the token is a session: long enough for a mobile app
        // to stay signed in between uses, short enough that a token copied out
        // of a device stops working on its own.
        SystemSetting::set('api_token_expire_value', '1');
        SystemSetting::set('api_token_expire_unit', 'days');

        // Idle browser-session lifetime — two hours, matching the default in
        // `config/session.php`. Same value-plus-unit shape as the API token so
        // both are edited the same way and bound by the same provider method.
        SystemSetting::set('web_session_expire_value', '120');
        SystemSetting::set('web_session_expire_unit', 'minutes');

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

        // API documentation visibility — Phase 12 Group A.
        //
        // Off by default. The document names every endpoint, field and status
        // code, so publishing it is a disclosure decision an operator makes on
        // purpose rather than a default a fresh install inherits. Off means the
        // reader has to be signed in and hold `api_docs.view`.
        SystemSetting::set('api_docs_public', 'false');

        // Audit trail export ceiling — Phase 10, §11 P10-E9.
        //
        // Five per HOUR, per user + IP. The number is the same one the route
        // enforced before it moved here; what changed is that an operator can now
        // read it and change it without a deploy, which the literal could not.
        SystemSetting::set('audit_export_rate_limit_per_hour', '5');

        // Manual retries on a failed export, PER ROW. Three clicks by default,
        // counted as clicks while the job already retries three times inside
        // each one.
        //
        // Same reason as the ceiling above: an operator whose exports keep dying
        // on something environmental should be able to turn the button off (0)
        // or widen it for an incident without waiting for a deploy. Read through
        // `AuditExport::maxRetries()`, which is also what the Action and the
        // resource call, so this row is the single source for all three.
        SystemSetting::set('audit_export_max_manual_retries', '3');

        // The three remaining route limiters — Phase 12 P12-2.
        //
        // Same reason as the export ceiling above: an operator who wants a
        // different number should not need a deploy. The literals they replace
        // were not wrong, they were unreachable — a mail relay ceiling an
        // admin cannot raise during an incident is a ceiling they must work
        // around in code.
        SystemSetting::set('user_state_actions_rate_limit_per_minute', '15');
        SystemSetting::set('bulk_action_rate_limit_per_minute', '5');
        SystemSetting::set('send_test_mail_rate_limit_per_minute', '5');

        // The health endpoint's ceiling — Phase 11 A1. The one limiter in the
        // app whose unit is a shared IP rather than a person, so the default is
        // 120/min (2/s) where the per-user limiters use 3-15. It is a setting
        // for the same reason as the three above, and more so: the address in
        // the counter can be a whole carrier's NAT, and the operator who
        // discovers that has to be able to raise it without a deploy.
        SystemSetting::set('health_rate_limit_per_minute', '120');

        // Manual retries on a failed JOB, per user + IP — Phase 11 §13.
        //
        // The mutating control on the queue monitor. `queues.retry` says who may
        // press it and nothing about how often, so without a ceiling a
        // compromised operator account re-dispatches work as fast as it can
        // click. Ten a minute is past an operator working a failure list by hand
        // and far below what a script needs; an operator draining a backlog
        // after an incident can raise it without a deploy.
        SystemSetting::set('queue_retry_rate_limit_per_minute', '10');

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
