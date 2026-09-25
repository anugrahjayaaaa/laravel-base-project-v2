<?php

namespace Tests\Feature\Auth\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PasswordExpiredEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected string $validPassword = 'Password123!';

    protected function setUp(): void
    {
        parent::setUp();

        $perm = Permission::create(['name' => 'users.unlock']);
        $role = Role::create(['name' => 'admin']);
        $role->givePermissionTo($perm);
    }

    /**
     * Create a user with a valid (non-expired, non-must-change) password.
     */
    private function activeUser(): User
    {
        $u = User::factory()->create([
            'password' => Hash::make($this->validPassword),
            'is_active' => true,
            'is_locked' => false,
            'must_change_password' => false,
            'password_expires_at' => null,
        ]);
        $u->assignRole('admin');
        return $u;
    }

    /**
     * Create a user whose password must be changed.
     */
    private function userMustChangePasswordAction(): User
    {
        $u = User::factory()->create([
            'password' => Hash::make($this->validPassword),
            'is_active' => true,
            'is_locked' => false,
            'must_change_password' => true,
            'password_expires_at' => null,
        ]);
        $u->assignRole('admin');
        return $u;
    }

    /**
     * Create a user whose password has expired (in the past).
     */
    private function userExpiredPassword(): User
    {
        $u = User::factory()->create([
            'password' => Hash::make($this->validPassword),
            'is_active' => true,
            'is_locked' => false,
            'must_change_password' => false,
            'password_expires_at' => now()->subDay(),
        ]);
        $u->assignRole('admin');
        return $u;
    }

    /**
     * Create a locked target user (so it can be unlocked).
     */
    private function lockedTarget(): User
    {
        return User::factory()->create([
            'password' => Hash::make($this->validPassword),
            'is_active' => true,
            'is_locked' => true,
        ]);
    }

    // ── Core Option C: protected endpoint must be blocked when password expired ──

    public function test_authenticated_valid_password_can_access_protected_endpoint(): void
    {
        $admin = $this->activeUser();
        $target = $this->lockedTarget();

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/v1/users/{$target->id}/unlock");

        // 200 = middleware passed (password was valid) + authorization passed (admin)
        $response->assertStatus(200);
        $response->assertJsonPath('data.message', 'User unlocked successfully.');
    }

    public function test_authenticated_expired_password_blocked_from_protected_endpoint(): void
    {
        $admin = $this->userExpiredPassword();
        $target = $this->lockedTarget();

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/v1/users/{$target->id}/unlock");

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'Password change required before accessing this resource.',
            'code' => 'PASSWORD_CHANGE_REQUIRED',
        ]);
    }

    public function test_authenticated_must_change_password_blocked_from_protected_endpoint(): void
    {
        $admin = $this->userMustChangePasswordAction();
        $target = $this->lockedTarget();

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/v1/users/{$target->id}/unlock");

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'Password change required before accessing this resource.',
            'code' => 'PASSWORD_CHANGE_REQUIRED',
        ]);
    }

    // ── Change-password endpoint must remain accessible when password expired ──

    public function test_expired_password_can_access_change_endpoint(): void
    {
        $user = $this->userExpiredPassword();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/auth/password/change', [
            'current_password' => $this->validPassword,
            'password' => 'BrandNew456!',
            'password_confirmation' => 'BrandNew456!',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.message', 'Password changed successfully.');

        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertNotNull($user->fresh()->password_expires_at);
        $this->assertTrue($user->fresh()->password_expires_at->isFuture());
    }

    public function test_must_change_password_can_access_change_endpoint(): void
    {
        $user = $this->userMustChangePasswordAction();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/auth/password/change', [
            'current_password' => $this->validPassword,
            'password' => 'BrandNew456!',
            'password_confirmation' => 'BrandNew456!',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.message', 'Password changed successfully.');

        $this->assertFalse($user->fresh()->must_change_password);
    }

    // ── After successful change, protected endpoint is accessible again ──

    public function test_password_change_resets_must_change_state_and_unblocks_protected_endpoint(): void
    {
        $user = $this->userMustChangePasswordAction();
        Sanctum::actingAs($user);

        // 1. Confirm the protected endpoint is blocked.
        $blocked = $this->postJson('/api/v1/auth/password/change', [
            'current_password' => $this->validPassword,
            'password' => 'BrandNew456!',
            'password_confirmation' => 'BrandNew456!',
        ]);
        $blocked->assertStatus(200);

        $this->assertFalse($user->fresh()->must_change_password);

        // 2. Create a target to unlock now that password state is reset.
        $target = $this->lockedTarget();

        // 3. Protected endpoint should now be accessible.
        $response = $this->postJson("/api/v1/users/{$target->id}/unlock");
        $response->assertStatus(200);
        $response->assertJsonPath('data.message', 'User unlocked successfully.');
    }

    // ── Public reset flows must NOT be affected by password-change enforcement ──

    public function test_public_forgot_password_not_affected_by_enforcement(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make($this->validPassword),
            'must_change_password' => true,
            'password_expires_at' => now()->subDay(),
        ]);

        // Public endpoint — no token, no password-change middleware.
        $response = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => $user->email,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.message', 'If the email exists, a reset link has been sent.');
    }

    public function test_public_reset_password_not_affected_by_enforcement(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make($this->validPassword),
            'must_change_password' => true,
            'password_expires_at' => now()->subDay(),
        ]);

        Notification::fake();
        $token = \Illuminate\Support\Facades\Password::broker()->createToken($user);

        $response = $this->postJson('/api/v1/auth/password/reset', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'BrandNew456!',
            'password_confirmation' => 'BrandNew456!',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.message', 'Password reset successfully.');
    }

    // ── Non-expired user without token is rejected from protected endpoints ──

    public function test_protected_endpoint_returns_401_without_token(): void
    {
        $target = User::factory()->create(['is_locked' => true]);

        $response = $this->postJson("/api/v1/users/{$target->id}/unlock");

        $response->assertStatus(401);
    }

    // ── Middleware returns JSON (not redirect) for API requests ──

    public function test_expired_password_middleware_returns_json_not_redirect(): void
    {
        $user = $this->userExpiredPassword();
        Sanctum::actingAs($user);

        $target = $this->lockedTarget();

        $response = $this->postJson("/api/v1/users/{$target->id}/unlock");

        $response->assertStatus(403);
        $response->assertJsonStructure(['message', 'code']);
    }
}
