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
     * Badge classes for this status, per design-system.md § Badges.
     *
     * This map used to exist three times: a closure in UserController::index,
     * a match() with string literals in users/edit.blade.php, and a third
     * variant table in components/ui/badge. They had already drifted — the view
     * copy keyed on raw strings instead of the enum — so adding a status would
     * have broken the edit page and left the index page working.
     *
     * Beside label() rather than in a view composer: this is the enum's own
     * presentation, it is needed by the API as much as by Blade, and a
     * composer that only exists to hand a class string to one view is the
     * controller closure again with a different name.
     *
     * @param  bool  $trashed  Soft-deleted overrides the status: the record is
     *                          gone, whatever its last status was.
     * @return string
     */
    public function badgeClass(bool $trashed = false): string
    {
        if ($trashed) {
            return 'bg-danger text-white';
        }

        return match ($this) {
            self::ACTIVE => 'bg-success-subtle text-success border border-success-subtle',
            self::INACTIVE => 'bg-secondary-subtle text-secondary border border-secondary-subtle',
            self::LOCKED => 'bg-warning-subtle text-warning border border-warning-subtle',
            self::PENDING_VERIFICATION => 'bg-warning-subtle text-dark border border-warning-subtle',
            self::TRASHED => 'bg-danger text-white',
        };
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
