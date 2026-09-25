<?php

namespace App\Support;

use App\Models\SystemSetting;

/**
 * IM8 password policy,single source of truth for password rules.
 *
 * Reads policy configuration from SystemSetting (admin-editable via /settings).
 * Provides validation and strength calculation for UI feedback.
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

        return $errors;
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
