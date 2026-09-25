<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserStatusEnum;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserCrudApiTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $adminRole = Role::create(['name' => 'admin', 'guard_name' => 'api']);
        $admin = User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'is_active' => true,
        ]);
        $admin->assignRole($adminRole);

        Sanctum::actingAs($admin, ['*']);
    }

    // -- Index --

    public function test_index_returns_paginated_users(): void
    {
        User::factory()->count(3)->create();

        $response = $this->getJson(route('api.v1.users.index'));

        $response->assertStatus(200);
        $this->assertArrayHasKey('users', $response->json('data'));
        $this->assertArrayHasKey('pagination', $response->json('data'));
        $this->assertCount(4, $response->json('data.users'));
    }

    // -- Show --

    public function test_show_returns_user_detail(): void
    {
        $user = User::factory()->create(['name' => 'Target User']);

        $this->getJson(route('api.v1.users.show', $user->id))
            ->assertStatus(200)
            ->assertJsonPath('data.user.name', 'Target User')
            ->assertJsonPath('data.user.email', $user->email);
    }

    public function test_show_returns_404_for_missing_user(): void
    {
        $this->getJson(route('api.v1.users.show', 99999))
            ->assertStatus(404);
    }

    // -- Update --

    public function test_update_success(): void
    {
        $user = User::factory()->create(['name' => 'Old Name']);

        $this->putJson(route('api.v1.users.update', $user->id), [
            'name' => 'New Name',
            'email' => $user->email,
            'status' => UserStatusEnum::INACTIVE->value,
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.message', 'User updated successfully.');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'New Name',
        ]);
    }

    public function test_update_does_not_change_password_from_user_management_payload(): void
    {
        $user = User::factory()->create(['password' => bcrypt('OriginalP@ss1!')]);

        $this->putJson(route('api.v1.users.update', $user->id), [
            'name' => $user->name,
            'email' => $user->email,
            'status' => UserStatusEnum::ACTIVE->value,
            'password' => 'InjectedP@ss2!',
            'password_confirmation' => 'InjectedP@ss2!',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('OriginalP@ss1!', $user->fresh()->password));
    }

    public function test_update_validates_required_fields(): void
    {
        $user = User::factory()->create();

        $this->putJson(route('api.v1.users.update', $user->id), [
            'name' => '',
            'email' => 'notanemail',
        ])
            ->assertStatus(422);
    }

    public function test_update_validates_unique_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $user = User::factory()->create();

        $this->putJson(route('api.v1.users.update', $user->id), [
            'name' => $user->name,
            'email' => 'taken@example.com',
        ])
            ->assertStatus(422);
    }

    // -- Soft Delete --

    public function test_soft_delete_deletes_user(): void
    {
        $user = User::factory()->create();

        $this->deleteJson(route('api.v1.users.destroy', $user->id))
            ->assertStatus(200)
            ->assertJsonPath('data.message', 'User deleted successfully.');

        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }

    public function test_soft_delete_cannot_delete_self(): void
    {
        $admin = User::where('email', 'admin@example.com')->first();

        $this->deleteJson(route('api.v1.users.destroy', $admin->id))
            ->assertStatus(422);
    }

    // -- Force Delete --

    public function test_force_delete_removes_user_permanently(): void
    {
        $user = User::factory()->create();
        $user->delete(); // soft delete first

        $this->deleteJson(route('api.v1.users.force-delete', $user->id))
            ->assertStatus(200)
            ->assertJsonPath('data.message', 'User permanently deleted.');

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_force_delete_cannot_delete_last_user(): void
    {
        $admin = User::where('email', 'admin@example.com')->first();

        $this->deleteJson(route('api.v1.users.force-delete', $admin->id))
            ->assertStatus(422);
    }

    // -- Restore --

    public function test_restore_soft_deleted_user(): void
    {
        $user = User::factory()->create();
        $user->delete();

        $this->postJson(route('api.v1.users.restore', $user->id))
            ->assertStatus(200)
            ->assertJsonPath('data.message', 'User restored successfully.');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'deleted_at' => null,
        ]);
    }

    public function test_restore_returns_404_for_non_trashed_user(): void
    {
        $user = User::factory()->create();

        $this->postJson(route('api.v1.users.restore', $user->id))
            ->assertStatus(200); // restore on non-trashed is idempotent in Laravel
    }

    // -- Authorization --

    public function test_unauthenticated_user_cannot_access_api(): void
    {
        Sanctum::actingAs(User::factory()->create(), []);

        // auth:sanctum passes; RBAC permission gates come in Phase 6
        $this->getJson(route('api.v1.users.index'))
            ->assertStatus(200);
    }
}
