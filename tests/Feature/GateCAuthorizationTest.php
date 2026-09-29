<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gate C: a user holding no permission gets nothing.
 *
 * The point of this file is the negative case. Each test asserts a 403 for a
 * user who was never granted anything, so a guard that silently reverts to
 * `return true` — the state this code was in before Phase 6 Group C — fails here
 * instead of shipping.
 */
class GateCAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $nobody;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        // No role at all: every check below has to come back empty.
        $this->nobody = User::factory()->create();
        $this->actingAs($this->nobody, 'web');

        $this->admin = User::factory()->create();
        $this->admin->assignRole(SystemRole::SUPERADMIN);
    }

    /**
     * A user with no permissions is refused every admin read route.
     *
     * Looped rather than a data provider: PHPUnit 11 dropped docblock providers,
     * and the attribute form is longer than the loop it replaces.
     */
    public function test_a_user_with_no_permissions_gets_403_on_every_admin_read_route(): void
    {
        foreach (['roles.index', 'permissions.index'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
    }

    public function test_a_user_with_no_permissions_cannot_create_a_user(): void
    {
        $this->post(route('users.store'), [
            'name' => 'Intruder',
            'email' => 'intruder@example.com',
            'username' => 'intruder',
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'intruder@example.com']);
    }

    public function test_a_user_with_no_permissions_cannot_update_another_user(): void
    {
        $target = User::factory()->create(['name' => 'Victim']);

        $this->put(route('users.update', $target), [
            'name' => 'Owned',
            'status' => 'active',
        ])->assertForbidden();

        $this->assertSame('Victim', $target->fresh()->name);
    }

    public function test_a_user_cannot_escalate_by_editing_their_own_profile(): void
    {
        // The self-profile exception in UpdateUserRequest is narrow on purpose:
        // it allows the edit, but not when a `roles` key rides along.
        $this->put(route('users.update', $this->nobody), [
            'name' => 'Escalated',
            'status' => 'active',
            'roles' => [SystemRole::SUPERADMIN],
        ])->assertForbidden();

        $this->assertFalse($this->nobody->fresh()->hasRole(SystemRole::SUPERADMIN));
    }

    public function test_a_user_with_no_permissions_cannot_bulk_delete(): void
    {
        $victim = User::factory()->create();

        $this->post(route('users.bulk-action'), [
            'action' => 'delete',
            'user_ids' => [$victim->id],
        ])->assertForbidden();

        $this->assertNull($victim->fresh()->deleted_at);
    }

    public function test_bulk_authorization_follows_the_requested_action(): void
    {
        // P6-C17: one permission does not imply the next action. A caller who
        // can lock must not thereby gain delete.
        $role = \App\Models\RoleLookup::find(SystemRole::USER);
        $role->givePermissionTo(
            \Spatie\Permission\Models\Permission::findByName('users.lock', 'web')
        );

        $this->nobody->assignRole($role);
        $this->nobody->unsetRelation('roles');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $victim = User::factory()->create();

        // delete -> users.delete, which this caller does not hold.
        $this->post(route('users.bulk-action'), [
            'action' => 'delete',
            'user_ids' => [$victim->id],
        ])->assertForbidden();

        $this->assertNull($victim->fresh()->deleted_at);
    }

    public function test_a_role_bulk_action_requires_its_own_permission(): void
    {
        // P6C1-005 regression: this route used to authorize unconditionally, so
        // any authenticated user could trash a role.
        $role = \App\Models\RoleLookup::find(SystemRole::USER);
        $this->nobody->assignRole($role);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $victimRole = \App\Models\Role::create([
            'name' => 'disposable-'.uniqid(),
            'guard_name' => \App\Models\RoleLookup::guard(),
        ]);

        $this->post(route('roles.bulk-action'), [
            'action' => 'delete',
            'role_ids' => [$victimRole->id],
        ])->assertForbidden();

        $this->assertNull($victimRole->fresh()->deleted_at);
    }

    public function test_settings_cannot_be_written_without_the_permission(): void
    {
        $this->post(route('settings.update'), [
            'password_min_length' => 4,
        ])->assertForbidden();

        $this->assertNotSame(4, (int) \App\Models\SystemSetting::getInt('password_min_length', 12));
    }
}
