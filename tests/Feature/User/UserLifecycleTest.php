<?php

namespace Tests\Feature\User;

use App\Models\User;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Same reason as UserStateWebTest: the API state routes are gated, and
        // this suite acted in as a role-less user.
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(SystemRole::SUPERADMIN);
        Sanctum::actingAs($this->admin, ['*']);
    }

    public function test_full_user_lifecycle(): void
    {
        // 1. Create inactive user
        $user = User::factory()->inactive()->create();
        $this->assertFalse($user->is_active);

        // 2. Activate
        $this->postJson(route('api.v1.users.activate', $user))
            ->assertStatus(200);
        $this->assertTrue($user->fresh()->is_active);

        // 3. Lock
        $this->postJson(route('api.v1.users.lock', $user))
            ->assertStatus(200);
        $this->assertTrue($user->fresh()->is_locked);

        // 4. Unlock
        $this->postJson(route('api.v1.users.unlock', $user))
            ->assertStatus(200);
        $this->assertFalse($user->fresh()->is_locked);

        // 5. Deactivate
        $this->postJson(route('api.v1.users.deactivate', $user))
            ->assertStatus(200);
        $this->assertFalse($user->fresh()->is_active);
    }
}
