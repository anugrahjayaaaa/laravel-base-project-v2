<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use App\Models\SystemSetting;

/**
 * Tracks failed login attempts and progressive lockout state per
 * identifier + IP scope. Serves as the durable store for the escalation
 * counter; the RateLimiter (cache) handles per-request throttling.
 */
#[Fillable(['identifier', 'ip_address', 'attempts', 'lock_count', 'locked_until'])]
class FailedLoginAttempt extends Model
{
    protected $casts = [
        'locked_until' => 'datetime',
        'attempts' => 'integer',
        'lock_count' => 'integer',
    ];

    /**
     * Find or create a record for the given identifier + IP scope.
     */
    public static function for(string $identifier, string $ip): static
    {
        return static::firstOrCreate(
            ['identifier' => $identifier, 'ip_address' => $ip],
            ['attempts' => 0, 'lock_count' => 0, 'locked_until' => null]
        );
    }

    /**
     * Is this record currently in a lockout state?
     */
    public function isLocked(): bool
    {
        return $this->locked_until !== null
            && $this->locked_until->isFuture();
    }

    /**
     * Calculate progressive lockout duration in minutes.
     *
     * First lock: base_minutes (5).
     * Each subsequent lock: +increment_minutes (10).
     * 5 → 15 → 25 → 35 → ...
     */
    public function nextLockoutMinutes(): int
    {
        $base = SystemSetting::getInt('lockout_base_minutes', 5);
        $increment = SystemSetting::getInt('lockout_increment_minutes', 10);

        return $base + ($this->lock_count * $increment);
    }
}
