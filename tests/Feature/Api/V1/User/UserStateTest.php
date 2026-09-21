<?php

namespace Tests\Feature\Api\V1\User;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserStateTest extends TestCase
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
        Sanctum::actingAs($this->admin, ['*']);
    }

    // -- Activate --

    public function test_activate_inactive_user_via_api(): void
    {
        $user = User::factory()->create(['is_active' => false, 'email_verified_at' => now()]);

        $this->postJson(route('api.v1.users.activate', $user))
            ->assertStatus(200)
            ->assertJsonPath('data.message', 'User activated successfully.');
    }

    public function test_activate_already_active_user_via_api(): void
    {
        $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);

        $this->postJson(route('api.v1.users.activate', $user))
            ->assertStatus(200)
            ->assertJsonPath('data.message', 'User activated successfully.');
    }

    public function test_activate_locked_user_via_api_forbidden(): void
    {
        $user = User::factory()->create(['is_active' => false, 'is_locked' => true, 'email_verified_at' => now()]);

        $this->postJson(route('api.v1.users.activate', $user))
            ->assertStatus(422);
    }

    // -- Deactivate --

    public function test_deactivate_active_user_via_api(): void
    {
        $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);

        $this->postJson(route('api.v1.users.deactivate', $user))
            ->assertStatus(200)
            ->assertJsonPath('data.message', 'User deactivated successfully.');
    }

    public function test_deactivate_locked_user_via_api_forbidden(): void
    {
        $user = User::factory()->create(['is_active' => true, 'is_locked' => true, 'email_verified_at' => now()]);

        $this->postJson(route('api.v1.users.deactivate', $user))
            ->assertStatus(422);
    }

    public function test_deactivate_self_forbidden_via_api(): void
    {
        $this->postJson(route('api.v1.users.deactivate', $this->admin))
            ->assertStatus(422);
    }

    // -- Lock --

    public function test_lock_active_user_via_api(): void
    {
        $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);

        $this->postJson(route('api.v1.users.lock', $user))
            ->assertStatus(200)
            ->assertJsonPath('data.message', 'User locked successfully.');
    }

    public function test_lock_inactive_user_via_api_forbidden(): void
    {
        $user = User::factory()->create(['is_active' => false, 'email_verified_at' => now()]);

        $this->postJson(route('api.v1.users.lock', $user))
            ->assertStatus(422);
    }

    public function test_lock_already_locked_user_via_api(): void
    {
        $user = User::factory()->create(['is_active' => true, 'is_locked' => true, 'email_verified_at' => now()]);

        $this->postJson(route('api.v1.users.lock', $user))
            ->assertStatus(200);
    }

    // -- Unlock --

    public function test_unlock_locked_user_via_api(): void
    {
        $user = User::factory()->create(['is_locked' => true, 'email_verified_at' => now()]);

        $this->postJson(route('api.v1.users.unlock', $user))
            ->assertStatus(200)
            ->assertJsonPath('data.message', 'User unlocked successfully.');
    }

    public function test_unlock_already_unlocked_user_via_api(): void
    {
        $user = User::factory()->create(['is_locked' => false, 'email_verified_at' => now()]);

        $this->postJson(route('api.v1.users.unlock', $user))
            ->assertStatus(200);
    }

    // -- Force Logout / Token Revocation --

    public function test_deactivate_revokes_api_tokens(): void
    {
        $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $user->createToken('test-token')->plainTextToken;

        $this->postJson(route('api.v1.users.deactivate', $user))
            ->assertStatus(200);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'tokenable_type' => User::class,
        ]);
    }

    public function test_lock_revokes_api_tokens(): void
    {
        $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $user->createToken('test-token')->plainTextToken;

        $this->postJson(route('api.v1.users.lock', $user))
            ->assertStatus(200);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'tokenable_type' => User::class,
        ]);
    }

    // -- Activate does NOT revoke tokens --

    public function test_activate_does_not_revoke_tokens(): void
    {
        $user = User::factory()->create(['is_active' => false, 'email_verified_at' => now()]);
        $user->createToken('test-token')->plainTextToken;

        $this->postJson(route('api.v1.users.activate', $user))
            ->assertStatus(200);

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    // -- Soft Delete / Force Logout --

    public function test_soft_delete_revokes_api_tokens_and_sessions(): void
    {
        $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $user->createToken('test-token')->plainTextToken;
        \Illuminate\Support\Facades\DB::table('sessions')->insert([
            'id' => 'test-session',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => '',
            'last_activity' => time(),
        ]);

        $this->delete(route('users.destroy', $user))->assertRedirect();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'tokenable_type' => User::class,
        ]);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertNull(\App\Models\User::withTrashed()->find($user->id)->remember_token);
    }
}
