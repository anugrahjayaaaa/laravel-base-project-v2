<?php

namespace Tests\Unit\Actions;

use App\Enums\UserStatusEnum;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserLifecycleFoundationTest extends TestCase
{
    use RefreshDatabase;

    // === UserStatusEnum::resolve() ===

    public function test_resolve_active_when_active_unlocked_verified(): void
    {
        $status = UserStatusEnum::resolve(true, false, '2025-01-01 00:00:00');
        $this->assertSame(UserStatusEnum::ACTIVE, $status);
    }

    public function test_resolve_inactive_when_not_active(): void
    {
        $status = UserStatusEnum::resolve(false, false, '2025-01-01 00:00:00');
        $this->assertSame(UserStatusEnum::INACTIVE, $status);
    }

    public function test_resolve_locked_when_locked_even_if_active(): void
    {
        $status = UserStatusEnum::resolve(true, true, '2025-01-01 00:00:00');
        $this->assertSame(UserStatusEnum::LOCKED, $status);
    }

    public function test_resolve_pending_verification_when_email_null(): void
    {
        $status = UserStatusEnum::resolve(true, false, null);
        $this->assertSame(UserStatusEnum::PENDING_VERIFICATION, $status);
    }

    public function test_resolve_pending_verification_takes_precedence_over_locked(): void
    {
        $status = UserStatusEnum::resolve(true, true, null);
        $this->assertSame(UserStatusEnum::PENDING_VERIFICATION, $status);
    }

    public function test_resolve_pending_verification_takes_precedence_over_inactive(): void
    {
        $status = UserStatusEnum::resolve(false, false, null);
        $this->assertSame(UserStatusEnum::PENDING_VERIFICATION, $status);
    }

    // === User model getStatus() ===

    public function test_user_get_status_returns_enum(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->assertInstanceOf(UserStatusEnum::class, $user->getStatus());
    }

    public function test_user_status_is_active(): void
    {
        $user = User::factory()->create(['is_active' => true, 'is_locked' => false, 'email_verified_at' => now()]);
        $this->assertSame(UserStatusEnum::ACTIVE, $user->getStatus());
    }

    public function test_user_status_is_inactive(): void
    {
        $user = User::factory()->create(['is_active' => false, 'email_verified_at' => now()]);
        $this->assertSame(UserStatusEnum::INACTIVE, $user->getStatus());
    }

    public function test_user_status_is_locked(): void
    {
        $user = User::factory()->create(['is_locked' => true, 'email_verified_at' => now()]);
        $this->assertSame(UserStatusEnum::LOCKED, $user->getStatus());
    }

    public function test_user_status_is_pending_verification(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $this->assertSame(UserStatusEnum::PENDING_VERIFICATION, $user->getStatus());
    }

    // === User model boolean helpers ===

    public function test_is_active_user_helper(): void
    {
        $user = User::factory()->create(['is_active' => true, 'is_locked' => false, 'email_verified_at' => now()]);
        $this->assertTrue($user->isActiveUser());
    }

    public function test_is_inactive_user_helper(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $this->assertTrue($user->isInactiveUser());
    }

    public function test_is_locked_user_helper(): void
    {
        $user = User::factory()->create(['is_locked' => true]);
        $this->assertTrue($user->isLockedUser());
    }

    public function test_is_pending_verification_helper(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $this->assertTrue($user->isPendingVerification());
    }

    // === User model scopes ===

    public function test_scope_active_filters_correctly(): void
    {
        $active = User::factory()->create(['is_active' => true, 'is_locked' => false, 'email_verified_at' => now()]);
        $locked = User::factory()->create(['is_active' => true, 'is_locked' => true, 'email_verified_at' => now()]);
        $inactive = User::factory()->create(['is_active' => false, 'email_verified_at' => now()]);

        $this->assertCount(1, User::active()->get());
        $this->assertTrue(User::active()->first()->is($active));
    }

    public function test_scope_inactive_filters_correctly(): void
    {
        $active = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $inactive = User::factory()->create(['is_active' => false]);

        $this->assertCount(1, User::inactive()->get());
        $this->assertTrue(User::inactive()->first()->is($inactive));
    }

    public function test_scope_locked_filters_correctly(): void
    {
        $locked = User::factory()->create(['is_locked' => true]);
        User::factory()->create(['is_locked' => false]);

        $this->assertCount(1, User::locked()->get());
        $this->assertTrue(User::locked()->first()->is($locked));
    }

    public function test_scope_pending_verification_filters_correctly(): void
    {
        $pending = User::factory()->create(['email_verified_at' => null]);
        User::factory()->create(['email_verified_at' => now()]);

        $this->assertCount(1, User::pendingVerification()->get());
        $this->assertTrue(User::pendingVerification()->first()->is($pending));
    }
}