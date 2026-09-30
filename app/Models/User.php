<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Enums\UserStatusEnum;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'username', 'email', 'password', 'is_active', 'is_locked', 'must_change_password', 'password_expires_at', 'last_activity_at', 'username_changed_at', 'email_changed_at', 'pending_email', 'email_change_token', 'email_change_token_expires_at'])]
#[Hidden(['password', 'remember_token'])]
/**
 * User model with status, lockout, and change-cooldown logic.
 */
class User extends Authenticatable implements MustVerifyEmail
{
    use Auditable;
    /** @use HasFactory<UserFactory> */
    use HasApiTokens;
    use HasFactory;
    use HasRoles;
    use Notifiable;
    use SoftDeletes;

    /**
     * The guard Spatie reads permissions under, pinned to the deployment.
     *
     * Spatie resolves this as `config('auth.defaults.guard')` intersected with
     * the guards this model can authenticate under. That default is MUTATED per
     * request: Sanctum's token resolution calls `Auth::shouldUse('sanctum')`, so
     * on any API request it resolves `sanctum` while the roles and permissions
     * are stored under `web`. Every `can()`, `hasRole()` and permission query
     * then reads an empty map — and because `Gate::before` calls `hasRole()`,
     * even the superadmin bypass quietly returns false.
     *
     * The permission guard is a property of the installation, not of the
     * request, so it is asked of RoleLookup (which reads the session guard from
     * config) rather than derived from ambient auth state. Same answer for a
     * browser session and for a token.
     */
    protected function getDefaultGuardName(): string
    {
        return RoleLookup::guard();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'is_locked' => 'boolean',
            'must_change_password' => 'boolean',
            'password_expires_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'username_changed_at' => 'datetime',
            'email_changed_at' => 'datetime',
            'email_change_token_expires_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // === Status (uses UserStatusEnum, no magic strings) ===

    /**
     * Get the resolved status enum for this user.
     *
     * @return UserStatusEnum
     */
    public function getStatus(): UserStatusEnum
    {
        return UserStatusEnum::resolve(
            $this->is_active,
            $this->is_locked,
            $this->email_verified_at?->format('Y-m-d H:i:s'),
        );
    }

    /**
     * Check if the user is active.
     *
     * @return bool
     */
    public function isActiveUser(): bool
    {
        return $this->getStatus()->value === UserStatusEnum::ACTIVE->value;
    }

    /**
     * The avatar initials for this user's name.
     *
     * One implementation, because the avatar is rendered on both the profile
     * and the user detail page and the two used to disagree: one took the
     * first two CHARACTERS, the other the first letter of the first two
     * WORDS. "Jaya Pratama" rendered as "JP" on one page and "JP" on the other
     * by luck, and "Budi" as "BU" versus "B".
     *
     *   Budi            -> BU   (one name, two letters)
     *   Jane Doe        -> JD   (two names, one letter each)
     *   John Ronald
     *   Reuel Tolkien   -> JT   (three or more, the standard: first + last)
     *
     * Three or more collapses to first + last because the middle names are
     * what run out of room first, and a two-letter "JR" for John Ronald
     * Reuel Tolkien is less recognisable than "JT".
     *
     * @return string
     */
    public function initials(): string
    {
        $words = preg_split('/\s+/', trim($this->name)) ?: [];

        if ($words === [] || $words === ['']) {
            return '';
        }

        if (count($words) === 1) {
            return strtoupper(str($words[0])->substr(0, 2));
        }

        if (count($words) === 2) {
            return strtoupper(str($words[0])[0].$words[1][0]);
        }

        return strtoupper(str($words[0])[0].$words[count($words) - 1][0]);
    }

    /**
     * Check if the user is inactive.
     *
     * @return bool
     */
    public function isInactiveUser(): bool
    {
        return $this->getStatus()->value === UserStatusEnum::INACTIVE->value;
    }

    /**
     * Check if the user is locked.
     *
     * @return bool
     */
    public function isLockedUser(): bool
    {
        return $this->getStatus()->value === UserStatusEnum::LOCKED->value;
    }

    /**
     * Check if the user's email is pending verification.
     *
     * @return bool
     */
    public function isPendingVerification(): bool
    {
        return $this->getStatus()->value === UserStatusEnum::PENDING_VERIFICATION->value;
    }

    /**
     * Scope query to active (not locked) users.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)->where('is_locked', false);
    }

    /**
     * Scope query to inactive users.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    /**
     * Scope query to locked users.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeLocked($query)
    {
        return $query->where('is_locked', true);
    }

    /**
     * Scope query to users pending email verification.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePendingVerification($query)
    {
        return $query->whereNull('email_verified_at');
    }

    /**
     * Check if the user can change their username (cooldown + setting).
     *
     * @return bool
     */
    public function canChangeUsername(): bool
    {
        if (! SystemSetting::getBool('allow_username_change', true)) {
            return false;
        }

        $cooldown = SystemSetting::getInt('username_change_cooldown_days', 0);

        if ($cooldown <= 0 || $this->username_changed_at === null) {
            return true;
        }

        return $this->username_changed_at->addDays($cooldown)->isPast();
    }

    /**
     * Check if the user can change their email (cooldown + setting).
     *
     * @return bool
     */
    public function canChangeEmail(): bool
    {
        if (! SystemSetting::getBool('allow_email_change', true)) {
            return false;
        }

        $cooldown = SystemSetting::getInt('email_change_cooldown_days', 0);

        if ($cooldown <= 0 || $this->email_changed_at === null) {
            return true;
        }

        return $this->email_changed_at->addDays($cooldown)->isPast();
    }
}
