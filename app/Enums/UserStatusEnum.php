<?php

namespace App\Enums;

/**
 * User account status enum.
 */
enum UserStatusEnum: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
    case LOCKED = 'locked';
    case PENDING_VERIFICATION = 'pending_verification';
    case TRASHED = 'trashed';

    /**
     * Get the human-readable label for this status.
     *
     * @return string Human-readable label (e.g. "Pending Verification")
     */
    public function label(): string
    {
        return str($this->value)->replace('_', ' ')->title();
    }

    /**
     * Resolve the primary status from the User model's boolean/date fields.
     *
     * Precedence: PENDING_VERIFICATION → LOCKED → INACTIVE → ACTIVE
     *
     * @param bool $isActive Whether the user is active
     * @param bool $isLocked Whether the user is locked
     * @param string|null $emailVerifiedAt Email verification timestamp
     * @return self
     */
    public static function resolve(bool $isActive, bool $isLocked, ?string $emailVerifiedAt): self
    {
        if ($emailVerifiedAt === null) {
            return self::PENDING_VERIFICATION;
        }

        if ($isLocked) {
            return self::LOCKED;
        }

        if (! $isActive) {
            return self::INACTIVE;
        }

        return self::ACTIVE;
    }
}
