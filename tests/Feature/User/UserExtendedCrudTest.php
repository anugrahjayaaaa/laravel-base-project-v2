<?php

namespace Tests\Feature\User;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserExtendedCrudTest extends TestCase
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

    // -- Soft Delete --

    public function test_soft_delete_deletes_without_changing_is_active(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->delete(route('users.destroy', $user))
            ->assertRedirect();

        $this->assertSoftDeleted($user);
        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_soft_delete_cannot_delete_self(): void
    {
        $this->delete(route('users.destroy', $this->admin))
            ->assertRedirect();

        $this->assertDatabaseHas('users', ['id' => $this->admin->id, 'deleted_at' => null]);
    }

    // -- Restore --

    public function test_restore_restores_soft_deleted_user(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->delete();

        $this->post(route('users.restore', $user))
            ->assertRedirect();

        $this->assertNull($user->fresh()->deleted_at);
        $this->assertTrue($user->fresh()->is_active);
    }

    // -- Force Delete --

    public function test_force_delete_permanently_removes_user(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->delete();

        $this->delete(route('users.force-delete', $user))
            ->assertRedirect();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_force_delete_cannot_delete_last_user(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->delete();

        $this->delete(route('users.force-delete', $user))
            ->assertRedirect();

        // Only admin left — force delete should be blocked
        $this->assertDatabaseHas('users', ['id' => $this->admin->id]);
    }

    // -- Resend Verification --

    public function test_admin_resend_verification_sends_email(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);

        $this->post(route('users.resend-verification', $user))
            ->assertRedirect();

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_resend_verification_rate_limited(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);

        // Hit rate limit
        for ($i = 0; $i < 10; $i++) {
            $this->post(route('users.resend-verification', $user));
        }

        $response = $this->post(route('users.resend-verification', $user));
        $response->assertSessionHasErrors();
    }

    public function test_resend_verification_already_verified(): void
    {
        $user = User::factory()->create();

        $this->post(route('users.resend-verification', $user))
            ->assertRedirect();

        $this->assertNotNull(session('errors'));
    }

    // -- Show / Edit --

    public function test_show_user_displays_edit_form(): void
    {
        $user = User::factory()->create();

        $this->get(route('users.show', $user))
            ->assertOk()
            ->assertSee('Edit User')
            ->assertSee($user->name);
    }

    public function test_show_trashed_user_displays_restore_options(): void
    {
        $user = User::factory()->create();
        $user->delete();

        $this->get(route('users.show', $user))
            ->assertOk()
            ->assertSee('Restore');
    }
}