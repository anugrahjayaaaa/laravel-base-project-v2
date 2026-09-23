<?php

namespace Tests\Feature\User;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserStateWebTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'is_active' => true,
        ]);
        $this->actingAs($this->admin, 'web');
    }

    // -- Activate --

    public function test_activate_inactive_user(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->post(route('users.activate', $user))
            ->assertRedirect()
            ->assertSessionHas('status', 'User activated successfully.');

        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_activate_already_active_user_no_error(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->post(route('users.activate', $user))
            ->assertRedirect();

        $this->assertTrue($user->fresh()->is_active);
    }

    // -- Deactivate --

    public function test_deactivate_active_user(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->post(route('users.deactivate', $user))
            ->assertRedirect()
            ->assertSessionHas('status', 'User deactivated successfully.');

        $this->assertFalse($user->fresh()->is_active);
    }

    public function test_deactivate_self_forbidden(): void
    {
        $this->post(route('users.deactivate', $this->admin));

        $this->assertTrue($this->admin->fresh()->is_active);
    }

    // -- Lock --

    public function test_lock_active_user(): void
    {
        $user = User::factory()->create(['is_locked' => false]);

        $this->post(route('users.lock', $user))
            ->assertRedirect()
            ->assertSessionHas('status', 'User locked successfully.');

        $this->assertTrue($user->fresh()->is_locked);
    }

    // -- Unlock --

    public function test_unlock_locked_user(): void
    {
        $user = User::factory()->create(['is_locked' => true]);

        $this->post(route('users.unlock', $user))
            ->assertRedirect()
            ->assertSessionHas('status', 'User unlocked successfully.');

        $this->assertFalse($user->fresh()->is_locked);
    }

    public function test_unlock_already_unlocked_user_no_error(): void
    {
        $user = User::factory()->create(['is_locked' => false]);

        $this->post(route('users.unlock', $user))
            ->assertRedirect();

        $this->assertFalse($user->fresh()->is_locked);
    }

    // -- State transitions --

    public function test_full_lifecycle_active_to_locked_to_unlocked(): void
    {
        $user = User::factory()->create(['is_active' => true, 'is_locked' => false]);

        // Lock
        $this->post(route('users.lock', $user));
        $this->assertTrue($user->fresh()->is_locked);

        // Unlock
        $this->post(route('users.unlock', $user));
        $this->assertFalse($user->fresh()->is_locked);
    }

    public function test_full_lifecycle_active_to_inactive_to_active(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        // Deactivate
        $this->post(route('users.deactivate', $user));
        $this->assertFalse($user->fresh()->is_active);

        // Activate
        $this->post(route('users.activate', $user));
        $this->assertTrue($user->fresh()->is_active);
    }

    // -- Guards --

    public function test_deactivate_locked_user_forbidden(): void
    {
        $user = User::factory()->create(['is_active' => true, 'is_locked' => true]);

        $this->post(route('users.deactivate', $user))
            ->assertRedirect()
            ->assertSessionHasErrors(['status']);

        $this->assertTrue($user->fresh()->is_locked); // still locked
    }

    public function test_lock_inactive_user_forbidden(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->post(route('users.lock', $user))
            ->assertRedirect()
            ->assertSessionHasErrors(['status']);

        $this->assertFalse($user->fresh()->is_locked); // still unlocked
    }

    public function test_activate_locked_user_forbidden(): void
    {
        $user = User::factory()->create(['is_active' => false, 'is_locked' => true]);

        $this->post(route('users.activate', $user))
            ->assertRedirect()
            ->assertSessionHasErrors(['status']);

        $this->assertFalse($user->fresh()->is_active); // still inactive
        $this->assertTrue($user->fresh()->is_locked); // still locked
    }
}
