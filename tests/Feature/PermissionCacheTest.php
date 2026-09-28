<?php

namespace Tests\Feature;

use App\Models\RoleLookup;
use App\Models\User;
use App\Support\SystemRole;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 6 Group B gate: the permission cache does not serve a stale answer.
 *
 * Spatie caches every permission and its role mapping in one cache key. The
 * cache is what makes `can()` cheap, and it is also the thing that will hand
 * back a stale answer after any write that forgot to flush it — the failure
 * mode is a permission that was granted still reading as denied, which looks
 * like a broken policy and is not one.
 *
 * Every test here forgets the cache in setUp so it observes a cold read, and
 * the point of each case is a write landing AFTER the cache is warm.
 */
class PermissionCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Cold cache: a warm one from a previous test would make these assert
        // nothing, since the seeder itself flushes before it writes.
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function forgetCache(): void
    {
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    #[Test]
    public function a_permission_granted_after_the_cache_warms_is_visible_immediately(): void
    {
        $this->seed(RoleSeeder::class);

        $permission = Permission::create([
            'name' => 'users.view',
            'guard_name' => RoleLookup::guard(),
        ]);

        // Warm the cache with the permission NOT yet attached to a role.
        $user = User::factory()->create();
        $user->assignRole(SystemRole::USER);
        $this->assertFalse($user->can('users.view'));

        $role = Role::where('name', SystemRole::USER)
            ->where('guard_name', RoleLookup::guard())
            ->firstOrFail();
        $role->givePermissionTo($permission);

        // givePermissionTo flushes the registrar. If it did not, this assertion
        // is the one that fails — and the reason the seeder flushes by hand.
        $this->assertTrue(
            $user->fresh()->can('users.view'),
            'A permission granted after the cache warmed must be visible without a manual flush.'
        );
    }

    #[Test]
    public function a_permission_revoked_after_the_cache_warms_stops_granting_immediately(): void
    {
        $this->seed(RoleSeeder::class);

        $permission = Permission::create([
            'name' => 'users.view',
            'guard_name' => RoleLookup::guard(),
        ]);

        $role = Role::where('name', SystemRole::USER)
            ->where('guard_name', RoleLookup::guard())
            ->firstOrFail();
        $role->givePermissionTo($permission);

        $user = User::factory()->create();
        $user->assignRole(SystemRole::USER);
        $this->assertTrue($user->can('users.view'));

        $role->revokePermissionTo($permission);

        $this->assertFalse(
            $user->fresh()->can('users.view'),
            'A revoked permission must stop granting on the next check.'
        );
    }

    #[Test]
    public function can_reflects_a_role_assigned_after_the_cache_warmed(): void
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->create();
        $user->assignRole(SystemRole::USER);
        $this->assertFalse($user->can('users.view'));

        $admin = Role::where('name', SystemRole::ADMIN)
            ->where('guard_name', RoleLookup::guard())
            ->firstOrFail();
        $admin->givePermissionTo(Permission::create([
            'name' => 'users.view',
            'guard_name' => RoleLookup::guard(),
        ]));

        $user->assignRole(SystemRole::ADMIN);

        $this->assertTrue(
            $user->fresh()->can('users.view'),
            'Gaining a role mid-session must change the answer on the next check.'
        );
    }

    #[Test]
    public function a_stale_cache_would_hide_a_write_made_behind_it(): void
    {
        // The negative control for the tests above: warm the cache, then write
        // straight to the table WITHOUT flushing, and confirm the registrar
        // really does serve the old answer. Without this, the three tests above
        // could pass because caching is not actually happening in the test
        // environment, and would then fail in production where it is.
        $this->seed(RoleSeeder::class);

        $permission = Permission::create([
            'name' => 'users.view',
            'guard_name' => RoleLookup::guard(),
        ]);

        $user = User::factory()->create();
        $user->assignRole(SystemRole::USER);
        $this->assertFalse($user->can('users.view'));

        $role = Role::where('name', SystemRole::USER)->firstOrFail();

        // Bypass the model: no observer, no cache reset.
        DB::table('role_has_permissions')->insert([
            'permission_id' => $permission->id,
            'role_id' => $role->id,
        ]);

        $this->assertFalse(
            $user->fresh()->can('users.view'),
            'The registrar is not serving from cache in tests; the cache tests above prove nothing.'
        );

        // And the documented escape hatch does fix it.
        $this->forgetCache();
        $this->assertTrue($user->fresh()->can('users.view'));
    }

    #[Test]
    public function superadmin_passes_without_the_catalogue_being_cached(): void
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->create();
        $user->assignRole(SystemRole::SUPERADMIN);

        // Nothing in the catalogue, and the cache is cold: Gate::before answers
        // from the role relationship, not from the permission cache.
        $this->assertTrue($user->can('a.permission.nobody.seeded'));
    }
}
