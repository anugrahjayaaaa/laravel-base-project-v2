<?php

/**
 * Rate-limit and lockout configuration.
 *
 * Configuration precedence (highest to lowest):
 * 1. Endpoint-specific policy (code)
 * 2. Runtime database settings (managed via dashboard)
 * 3. This static config file
 * 4. Laravel framework defaults
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Login rate limiting & progressive lockout
    |--------------------------------------------------------------------------
    |
    | The RateLimiter facade (cache-backed) is used for the login endpoint
    | throttle. Account-level progressive lockout state is persisted in the
    | failed_login_attempts table for durability and UI/API visibility.
    |
    | Lockout scope: login identifier (email) + IP address.
    | Progressive lockout: 5 → 15 → 25 → 35 minutes.
    | Duration = base_minutes + (lock_count * increment_minutes).
    | Successful login resets all counters (attempts, lock_count).
    */

    'login' => [
        // Max failed attempts before lockout triggers.
        'max_attempts' => env('AUTH_LOGIN_MAX_ATTEMPTS', 5),

        // Base lockout duration (first lock) in minutes.
        'lockout_base_minutes' => env('AUTH_LOGIN_LOCKOUT_BASE_MINUTES', 5),

        // Additional minutes added per successive lock.
        'lockout_increment_minutes' => env('AUTH_LOGIN_LOCKOUT_INCREMENT_MINUTES', 10),

        // Transport-level rate limit (RateLimiter), per IP + identifier.
        'rate_limit_per_minute' => env('AUTH_LOGIN_RATE_LIMIT_PER_MINUTE', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Password reset / verification rate limits
    |--------------------------------------------------------------------------
    */

    'password_forgot' => [
        'rate_limit_per_minute' => env('AUTH_PASSWORD_FORGOT_RATE_LIMIT', 3),
    ],

    'password_reset' => [
        'rate_limit_per_minute' => env('AUTH_PASSWORD_RESET_RATE_LIMIT', 3),
        'token_expire_minutes' => env('AUTH_PASSWORD_RESET_EXPIRE_MINUTES', 15),
    ],

    'email_verification' => [
        'token_expire_minutes' => env('AUTH_EMAIL_VERIFICATION_EXPIRE_MINUTES', 60),
        'rate_limit_per_hour' => env('AUTH_EMAIL_VERIFICATION_RATE_LIMIT', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Password policy & lifecycle
    |--------------------------------------------------------------------------
    |
    | Uses Illuminate\Validation\Rules\Password (IM8: min 8, mixed case,
    | numbers, symbols — per docs/base/dependencies/overview.md §Password policy).
    |
    | Password expiration is checked via password_expires_at on the User model.
    | Password history prevents reuse of the last N password hashes.
    */

    'password_policy' => [
        'min' => env('AUTH_PASSWORD_MIN_LENGTH', 8),
        'mixed_case' => env('AUTH_PASSWORD_MIXED_CASE', true),
        'numbers' => env('AUTH_PASSWORD_NUMBERS', true),
        'symbols' => env('AUTH_PASSWORD_SYMBOLS', true),
        'uncompromised' => env('AUTH_PASSWORD_UNCOMPROMISED', false),
    ],

    'password_history' => [
        'count' => env('AUTH_PASSWORD_HISTORY_COUNT', 5),
    ],

    'password_expiration_days' => env('AUTH_PASSWORD_EXPIRATION_DAYS', 90),

];
