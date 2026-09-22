<?php

namespace App\Models;

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

#[Fillable(['name', 'username', 'email', 'password', 'is_active', 'is_locked', 'must_change_password', 'password_expires_at', 'last_activity_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens;
    use HasFactory;
    use HasRoles;
    use Notifiable;
    use SoftDeletes;

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

    public function getStatus(): UserStatusEnum
    {
        return UserStatusEnum::resolve(
            $this->is_active,
            $this->is_locked,
            $this->email_verified_at?->format('Y-m-d H:i:s'),
        );
    }

    public function isActiveUser(): bool
    {
        return $this->getStatus()->value === UserStatusEnum::ACTIVE->value;
    }

    public function isInactiveUser(): bool
    {
        return $this->getStatus()->value === UserStatusEnum::INACTIVE->value;
    }

    public function isLockedUser(): bool
    {
        return $this->getStatus()->value === UserStatusEnum::LOCKED->value;
    }

    public function isPendingVerification(): bool
    {
        return $this->getStatus()->value === UserStatusEnum::PENDING_VERIFICATION->value;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->where('is_locked', false);
    }

    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    public function scopeLocked($query)
    {
        return $query->where('is_locked', true);
    }

    public function scopePendingVerification($query)
    {
        return $query->whereNull('email_verified_at');
    }
}
