<?php

namespace App\Support;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * IM8 password policy,single source of truth for password rules.
 *
 * Reads policy configuration from SystemSetting (admin-editable via /settings).
 * Provides validation and strength calculation for UI feedback.
 *
 * The settings keys here ARE the contract with the /settings form. A key this
 * class reads that the form does not write is unreachable for an admin; a key
 * the form writes that this class does not read is a toggle that does nothing.
 * PolicyKeyTest pins the two lists together.
 */
class PasswordPolicy
{
    /**
     * Validate a password against the current policy.
     *
     * @param  string  $password  The password to validate.
     * @param  string|null  $username  Username to reject in password (if enabled).
     * @return array<string> List of error messages (empty = valid).
     */
    public static function validate(string $password, ?string $username = null): array
    {
        $errors = [];

        $minLength = SystemSetting::getInt('password_min_length', 12);
        if (strlen($password) < $minLength) {
            $errors[] = "Password must be at least {$minLength} characters.";
        }

        if (SystemSetting::getBool('password_require_upper', true)) {
            if (! preg_match('/[A-Z]/', $password)) {
                $errors[] = 'Password must contain at least one uppercase letter.';
            }
        }

        if (SystemSetting::getBool('password_require_lower', true)) {
            if (!preg_match('/[a-z]/', $password)) {
                $errors[] = 'Password must contain at least one lowercase letter.';
            }
        }

        if (SystemSetting::getBool('password_require_digit', true)) {
            if (!preg_match('/[0-9]/', $password)) {
                $errors[] = 'Password must contain at least one number.';
            }
        }

        if (SystemSetting::getBool('password_require_symbol', true)) {
            if (!preg_match('/[^A-Za-z0-9]/', $password)) {
                $errors[] = 'Password must contain at least one symbol.';
            }
        }

        if ($username && SystemSetting::getBool('password_reject_username', true)) {
            if (stripos($password, $username) !== false) {
                $errors[] = 'Password must not contain your username.';
            }
        }

        // Deliberately last: it is the only rule that leaves the server, and a
        // password failing the cheap checks above is not worth a round trip.
        if (SystemSetting::getBool('password_uncompromised', false) && self::isPwned($password)) {
            $errors[] = 'This password has appeared in a public data breach. Please choose another.';
        }

        return $errors;
    }

    /**
     * Has this password turned up in a public breach?
     *
     * Laravel's own verifier, which is the point: it uses HIBP's k-anonymity
     * range API, so only the first five characters of the SHA-1 are sent and
     * the password itself never leaves this server. No extra dependency for
     * that — the rule ships with the framework.
     *
     * Fail-OPEN, and not by choice here: NotPwnedVerifier::search() reports
     * the connection error and returns an empty result set, so a HIBP outage
     * means "not pwned" rather than locking every user out of their own
     * account. The complexity rules still apply, and only this one check is
     * skipped.
     *
     * ponytail: runs inline on every validation. Cache the per-password result
     * if the latency shows up — Hash::make-style memoisation, or check the
     * candidate once at the form and not again on submit.
     */
    private static function isPwned(string $password): bool
    {
        // uncompromised() is an instance method, so it has to be entered
        // through the fluent builder like every other Password rule.
        return Validator::make(
            ['password' => $password],
            ['password' => [Password::min(1)->uncompromised()]]
        )->fails();
    }

    /**
     * Calculate password strength for UI feedback.
     *
     * @param  string  $password
     * @return array{percent: int, label: string, rules: array<string, bool>}
     */
    public static function strength(string $password): array
    {
        $rules = [
            'length' => strlen($password) >= SystemSetting::getInt('password_min_length', 12),
            'upper' => (bool) preg_match('/[A-Z]/', $password),
            'lower' => (bool) preg_match('/[a-z]/', $password),
            'digit' => (bool) preg_match('/[0-9]/', $password),
            'symbol' => (bool) preg_match('/[^A-Za-z0-9]/', $password),
        ];

        $passed = count(array_filter($rules));
        $total = count($rules);
        $percent = $total > 0 ? (int) round(($passed / $total) * 100) : 0;

        $label = $percent <= 40 ? 'weak' : ($percent <= 60 ? 'medium' : 'strong');

        return [
            'percent' => $percent,
            'label' => $label,
            'rules' => $rules,
        ];
    }
}
