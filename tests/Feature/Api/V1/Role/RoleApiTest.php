<?php

namespace Tests\Feature\Api\V1\Role;

use App\Models\Role as AppRole;
use App\Models\RoleLookup;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The role API at its authorization boundary.
 *
 * Deep behaviour — system-role guards, trash/restore, the listing columns —
 * lives in `Tests\Feature\Role\RbacApiTest`. This file owns the one question
 * that class does not ask: is each route gated on the permission the operation
 * needs, or merely on a sibling from the same module? A route written
 * `can('roles.view')` where `roles.create` was meant passes every
 * zero-permission test, so both directions are exercised here.
 */
class RoleApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        // The seeders write pivots directly, so Spatie's cached map is stale.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * An ordinary role wearing exactly the given permissions.
     */
    private function userWith(string ...$permissions): User
    {
        $role = AppRole::create([
            'name' => 'api-role-'.uniqid(),
            'guard_name' => RoleLookup::guard(),
        ]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->assignRole($role);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    public function test_a_holder_of_roles_create_creates_a_role(): void
    {
        Sanctum::actingAs($this->userWith('roles.create'));

        $this->postJson(route('api.v1.roles.store'), ['name' => 'made-by-api'])
            ->assertCreated()
            ->assertJsonPath('data.role.name', 'made-by-api');
    }

    public function test_a_reader_cannot_create_a_role(): void
    {
        // Holds the read sibling, so the refusal is the missing create
        // permission rather than an empty grant.
        Sanctum::actingAs($this->userWith('roles.view'));

        $this->postJson(route('api.v1.roles.store'), ['name' => 'nope'])->assertForbidden();

        $this->assertDatabaseMissing('roles', ['name' => 'nope']);
    }

    public function test_a_creator_cannot_read_the_role_listing(): void
    {
        // The other direction: create must not imply view.
        Sanctum::actingAs($this->userWith('roles.create'));

        $this->getJson(route('api.v1.roles.index'))->assertForbidden();
    }

    public function test_an_invalid_role_carries_the_validation_envelope(): void
    {
        Sanctum::actingAs($this->userWith('roles.create'));

        $this->postJson(route('api.v1.roles.store'), ['name' => ''])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonStructure([
                'message',
                'errors' => ['name'],
                'code',
                'meta' => ['request_id', 'timestamp'],
            ]);
    }
}
