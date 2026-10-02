<?php

namespace App\Actions\V1\System;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Persist validated system settings using the shared web/API mapping.
 *
 * Controllers remain responsible for request validation and response
 * formatting. This action owns setting normalization, persistence and the
 * `system_setting.updated` audit record, so both channels write the row the same
 * way and a rollback takes it with the settings it describes.
 *
 * The whitelist below is the gate every setting has to pass. A key that is
 * validated and rendered but missing here is dropped without a warning — the
 * form saves "successfully" and the value never changes. SystemSettingRequest
 * must stay a subset of $updates; SettingsPersistenceTest enforces it.
 */
class SystemSettingsUpdateAction
{
    /**
     * Normalize and persist the settings payload.
     *
     * The two callers mean different things by a missing key, and only one of
     * them can be right:
     *
     * - The web form posts every field, and an unchecked checkbox simply is not
     *   submitted. There, a missing boolean means OFF, so it falls back to the
     *   hardcoded default. That is the historical contract.
     * - An API client sends only the keys it means to change. There, a missing
     *   key means LEAVE IT ALONE. Falling back to the default reset
     *   `password_min_length` to 8 and switched `registration_enabled` off in
     *   the same call, silently disarming the password policy and self-signup.
     *
     * So the API passes $partial and absent keys are backfilled from what is
     * stored instead. The list of keys and their types stays here, in one place.
     *
     * @param array<string, mixed> $data
     * @param bool                 $partial  True when absent means "unchanged"
     *                                       rather than "reset to default".
     * @param User|null            $causer   Who to attribute the audit record to
     */
    public function run(array $data, bool $partial = false, ?User $causer = null): void
    {
        if ($partial) {
            $data = $this->backfill($data);
        }

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
            // 12 is the floor, not a preference: the seeder writes 12 and
            // PasswordPolicy falls back to 12, so a form that pre-filled 8
            // showed an admin a policy weaker than the one being enforced.
            'password_min_length' => (string) ($data['password_min_length'] ?? 12),
            'password_require_upper' => ($data['password_require_upper'] ?? true) ? 'true' : 'false',
            'password_require_lower' => ($data['password_require_lower'] ?? true) ? 'true' : 'false',
            'password_require_digit' => ($data['password_require_digit'] ?? true) ? 'true' : 'false',
            'password_require_symbol' => ($data['password_require_symbol'] ?? true) ? 'true' : 'false',
            'password_reject_username' => ($data['password_reject_username'] ?? true) ? 'true' : 'false',
            'password_uncompromised' => ($data['password_uncompromised'] ?? false) ? 'true' : 'false',
            'password_history_enabled' => ($data['password_history_enabled'] ?? false) ? 'true' : 'false',
            'password_history_count' => (string) ($data['password_history_count'] ?? 5),
            'password_expiry_enabled' => ($data['password_expiry_enabled'] ?? false) ? 'true' : 'false',
            'password_expiry_days' => (string) ($data['password_expiry_days'] ?? 90),
            'password_expiry_warn_days' => (string) ($data['password_expiry_warn_days'] ?? 14),
            'password_security_sweep_time' => $data['password_security_sweep_time'] ?? '00:00',
            'password_security_sweep_timezone' => $data['password_security_sweep_timezone'] ?? '',
            'inactivity_lock_enabled' => ($data['inactivity_lock_enabled'] ?? false) ? 'true' : 'false',
            'inactivity_lock_days' => (string) ($data['inactivity_lock_days'] ?? 30),
            'inactivity_lock_grace_enabled' => (
                array_key_exists('inactivity_lock_grace_enabled', $data)
                    ? (bool) $data['inactivity_lock_grace_enabled']
                    : SystemSetting::getBool('inactivity_lock_grace_enabled', true)
            ) ? 'true' : 'false',

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

            // Self-registration
            'registration_enabled' => ($data['registration_enabled'] ?? false) ? 'true' : 'false',
            'registration_default_role' => $data['registration_default_role'] ?? 'user',
            'registration_rate_limit_per_minute' => (string) ($data['registration_rate_limit_per_minute'] ?? 3),
        ];

        $graceEnabled = $updates['inactivity_lock_grace_enabled'] === 'true';

        if ($graceEnabled && array_key_exists('inactivity_lock_grace_days', $data)) {
            $updates['inactivity_lock_grace_days'] = (string) $data['inactivity_lock_grace_days'];
        }

        // One transaction for all ~40 keys. `SystemSetting::set()` writes a single row
        // per key with no transaction of its own, so before this the loop could
        // stop half way — a failure on key 30 left the password policy updated
        // and the registration toggle not, with nothing to roll back and an audit
        // row (written later, by the controller) claiming the save succeeded.
        DB::transaction(function () use ($updates, $data, $causer): void {
            foreach ($updates as $key => $value) {
                SystemSetting::set($key, $value);
            }

            if ($causer !== null) {
                SystemSetting::query()->firstOrFail()->audit('system_setting.updated', $causer, $data);
            }
        });
    }

    /**
     * Fill absent keys with what is currently stored, so a partial payload
     * writes only what it named.
     *
     * Stored booleans are the strings 'true'/'false' and PHP reads 'false' as
     * truthy, so those come back as real booleans. The validation rules already
     * cast an incoming '0'/'1' the same way, so both sides of the merge are the
     * same type and the normalization below is a no-op for them.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function backfill(array $data): array
    {
        $stored = SystemSetting::getAll();

        foreach ($stored as $key => $value) {
            if (array_key_exists($key, $data)) {
                continue;
            }

            $data[$key] = $value === 'true' ? true : ($value === 'false' ? false : $value);
        }

        return $data;
    }
}
