<?php

namespace App\Enums;

enum UserStatusEnum: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
    case LOCKED = 'locked';
    case PENDING_VERIFICATION = 'pending_verification';
    case TRASHED = 'trashed';

    public function label(): string
    {
        return str($this->value)->replace('_', ' ')->title();
    }

    /**
     * Resolve the primary status from the User model's boolean/date fields.
     *
     * Precedence: PENDING_VERIFICATION → LOCKED → INACTIVE → ACTIVE
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
