<?php

namespace App\Auth;

use App\Models\FailedLoginAttempt;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Shared login throttle + progressive lockout logic.
 *
 * Uses Laravel's RateLimiter (cache-backed) for per-request throttling
 * and the failed_login_attempts table for durable escalation state.
 *
 * Scope: login identifier (email) + IP address.
 *
 * @see docs/base/security/rate-limiting.md
 * @see docs/base/security/authentication.md
 */
class LoginThrottle
{
    /**
     * Build a namespaced, human-readable rate-limiter key.
     * Format: rate_limit:{feature}:{type}:{identifier}:{ip}
     * Examples:
     *   rate_limit:login:email:user@example.com:127.0.0.1
     *   rate_limit:login:username:jaya:127.0.0.1
     *   rate_limit:login:user_id:42:127.0.0.1
     */
    public function key(string $feature, string $identifier, string $ip): string
    {
        $id = strtolower(trim($identifier));

        if (str_contains($id, '@')) {
            $type = 'email';
            $slug = $id;
        } elseif (ctype_digit($id) && $id > 0) {
            $type = 'user_id';
            $slug = (int) $id;
        } else {
            $type = 'username';
            $slug = $id;
        }

        return "rate_limit:{$feature}:{$type}:{$slug}:{$ip}";
    }

    /**
     * Check if the identifier + IP is currently locked out.
     * Checks DB first (durable lock), then RateLimiter (transport throttle).
     */
    public function isLocked(string $identifier, string $ip): bool
    {
        $record = FailedLoginAttempt::where('identifier', $identifier)
            ->where('ip_address', $ip)
            ->first();

        if ($record && $record->isLocked()) {
            return true;
        }

        $max = SystemSetting::getInt('auth_login_max_attempts', 5);

        return RateLimiter::tooManyAttempts($this->key('login', $identifier, $ip), $max);
    }

    /**
     * Check if a previously-set DB lock has expired and should be cleared.
     * Returns true when an expired lock was found (and cleared).
     */
    public function clearIfExpired(string $identifier, string $ip): bool
    {
        // ponytail: SQLite in-memory tests lack real row locking;
        // production uses MySQL/PostgreSQL row-level locks.
        return DB::transaction(function () use ($identifier, $ip) {
            $record = FailedLoginAttempt::where('identifier', $identifier)
                ->where('ip_address', $ip)
                ->lockForUpdate()
                ->first();

            if ($record && $record->locked_until && $record->locked_until->isPast()) {
                $this->reset($identifier, $ip);

                return true;
            }

            return false;
        });
    }

    /**
     * Record a failed login attempt and escalate lockout if threshold reached.
     * Returns the lock duration in seconds (0 if not locked).
     *
     * @return int Lock duration in seconds, or 0 if not locked.
     */
    public function recordFailed(string $identifier, string $ip, ?User $user = null): int
    {
        $key = $this->key('login', $identifier, $ip);
        $maxAttempts = SystemSetting::getInt('auth_login_max_attempts', 5);

        // Hit the rate limiter (cache-backed transport throttle).
        RateLimiter::hit($key, 60);

        // Persist escalation state in the DB (within a transaction for
        // race-safety — see docs/base/security/rate-limiting.md).
        // ponytail: SQLite lacks real row locking; relies on test-level
        // sequential execution. Production uses MySQL/PostgreSQL.
        $lockedSeconds = 0;

        DB::transaction(function () use ($identifier, $ip, $maxAttempts, $key, $user, &$lockedSeconds) {
            $record = FailedLoginAttempt::firstOrCreate(
                ['identifier' => $identifier, 'ip_address' => $ip],
                ['attempts' => 0, 'lock_count' => 0, 'locked_until' => null]
            );

            $record->attempts++;

            if ($user) {
                $record->user_id = $user->getKey();
            }

            if (! $record->isLocked() && $record->attempts > $maxAttempts) {
                $durationMinutes = $record->nextLockoutMinutes();
                $record->lock_count++;
                $record->locked_until = now()->addMinutes($durationMinutes);
                $record->save();

                // Clear cache counter — DB lock governs the window now.
                RateLimiter::clear($key);

                $lockedSeconds = $durationMinutes * 60;
            } else {
                $record->save();
            }
        });

        return $lockedSeconds;
    }

    /**
     * Reset all failed-login state (on successful login or admin unlock).
     */
    public function reset(string $identifier, string $ip): void
    {
        $key = $this->key('login', $identifier, $ip);
        RateLimiter::clear($key);

        FailedLoginAttempt::where('identifier', $identifier)
            ->where('ip_address', $ip)
            ->delete();
    }

    /**
     * Seconds remaining until the current lockout expires.
     */
    public function lockedFor(string $identifier, string $ip): int
    {
        $record = FailedLoginAttempt::where('identifier', $identifier)
            ->where('ip_address', $ip)
            ->first();

        if ($record && $record->locked_until) {
            return (int) now()->diffInSeconds($record->locked_until, false);
        }

        return RateLimiter::availableIn($this->key('login', $identifier, $ip));
    }
}
